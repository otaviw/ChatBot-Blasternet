<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Message;
use App\Services\InboundMessageService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        private InboundMessageService $inboundMessage
    ) {}

    /**
     * Verificação do webhook (GET). Meta envia hub.mode, hub.verify_token, hub.challenge.
     * Configure WHATSAPP_VERIFY_TOKEN no .env com o mesmo valor do painel Meta.
     */
    public function verify(Request $request)
    {
        $mode = $request->input('hub_mode');
        $token = $request->input('hub_verify_token');
        $challenge = $request->input('hub_challenge');

        // fallback caso venha com ponto
        if (!$mode) {
            $mode = $request->query('hub.mode');
        }

        if (!$token) {
            $token = $request->query('hub.verify_token');
        }

        if (!$challenge) {
            $challenge = $request->query('hub.challenge');
        }

        $expectedToken = config('whatsapp.verify_token');

        if ($mode === 'subscribe' && $token === $expectedToken) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * Recebe notificações do Meta (POST). Payload: entry[].changes[].value (metadata, messages).
     */
    public function handle(Request $request): Response
    {
        $body = $request->all();

        if (($body['object'] ?? null) !== 'whatsapp_business_account') {
            return response('', 200);
        }

        foreach ($body['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) !== 'messages') {
                    continue;
                }
                $this->processValue($change['value'] ?? []);
            }
        }

        return response('', 200);
    }

    private function processValue(array $value): void
    {
        $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
        $company = $this->resolveCompany($phoneNumberId);

        if (!$company) {
            \Log::warning('Webhook recebido para phone_number_id desconhecido', [
                'phone_number_id' => $phoneNumberId,
            ]);
            return;
        }

        $this->processStatuses($company, $value);

        Log::info('Webhook WhatsApp company resolvida por metadata.phone_number_id.', [
            'phone_number_id' => $phoneNumberId,
            'company_id' => $company->id,
        ]);

        $contactNameByWaId = [];
        foreach ($value['contacts'] ?? [] as $contact) {
            $waId = trim((string) ($contact['wa_id'] ?? ''));
            $name = trim((string) ($contact['profile']['name'] ?? ''));
            if ($waId === '' || $name === '') {
                continue;
            }

            $contactNameByWaId[$waId] = $name;
        }

        foreach ($value['messages'] ?? [] as $msg) {
            $from = (string) ($msg['from'] ?? '');
            $messageId = (string) ($msg['id'] ?? '');
            $messageType = (string) ($msg['type'] ?? '');
            $contactName = $contactNameByWaId[$from] ?? null;

            if (trim($from) === '') {
                continue;
            }

            if ($messageType === 'text') {
                $text = (string) ($msg['text']['body'] ?? '');
                if (trim($text) === '') {
                    continue;
                }

                $this->inboundMessage->handleIncomingText(
                    $company,
                    $from,
                    $text,
                    ['wamid' => $messageId, 'from' => $from, 'source' => 'webhook'],
                    ['source' => 'webhook'],
                    true,
                    $contactName
                );

                continue;
            }

            if ($messageType === 'image') {
                $mediaId = (string) ($msg['image']['id'] ?? '');
                $caption = (string) ($msg['image']['caption'] ?? '');
                if (trim($mediaId) === '') {
                    continue;
                }

                $this->inboundMessage->handleIncomingImage(
                    $company,
                    $from,
                    $mediaId,
                    $caption,
                    [
                        'wamid' => $messageId,
                        'from' => $from,
                        'source' => 'webhook',
                        'incoming_type' => 'image',
                    ],
                    $contactName
                );

                Log::info('Webhook WhatsApp recebido com tipo ainda nao tratado para auto resposta.', [
                    'company_id' => $company->id,
                    'from' => $from,
                    'message_type' => $messageType,
                    'message_id' => $messageId,
                ]);
            }
        }
    }

    /** Encontra company exclusivamente pelo meta_phone_number_id. */
    private function resolveCompany(string $phoneNumberId): ?Company
    {
        if ($phoneNumberId !== '') {
            $company = Company::with('botSetting')
                ->where('meta_phone_number_id', $phoneNumberId)
                ->first();
            if ($company) {
                return $company;
            }

            Log::warning('Webhook WhatsApp sem company correspondente para phone_number_id.', [
                'phone_number_id' => $phoneNumberId,
            ]);
        }

        return null;
    }

    private function processStatuses(Company $company, array $value): void
    {
        $statuses = $value['statuses'] ?? null;
        if (! is_array($statuses) || $statuses === []) {
            return;
        }

        $metadata = is_array($value['metadata'] ?? null) ? $value['metadata'] : [];

        Log::info('Webhook WhatsApp recebeu status de entrega.', [
            'company_id' => (int) $company->id,
            'statuses_count' => count($statuses),
            'phone_number_id' => $metadata['phone_number_id'] ?? null,
        ]);

        foreach ($statuses as $statusPayload) {
            if (! is_array($statusPayload)) {
                continue;
            }

            $this->applyStatusUpdate($company, $statusPayload, $metadata);
        }
    }

    /**
     * @param  array<string, mixed>  $statusPayload
     * @param  array<string, mixed>  $metadata
     */
    private function applyStatusUpdate(Company $company, array $statusPayload, array $metadata): void
    {
        $statusName = mb_strtolower(trim((string) ($statusPayload['status'] ?? '')));
        $whatsAppMessageId = trim((string) ($statusPayload['id'] ?? ''));

        if ($statusName === '' || $whatsAppMessageId === '') {
            Log::warning('Webhook status ignorado por payload incompleto.', [
                'company_id' => (int) $company->id,
                'status' => $statusPayload['status'] ?? null,
                'status_id' => $statusPayload['id'] ?? null,
            ]);

            return;
        }

        $message = Message::query()
            ->where('whatsapp_message_id', $whatsAppMessageId)
            ->whereHas('conversation', function ($query) use ($company) {
                $query->where('company_id', (int) $company->id);
            })
            ->first();

        if (! $message) {
            Log::info('Webhook status recebido sem mensagem correspondente.', [
                'company_id' => (int) $company->id,
                'whatsapp_message_id' => $whatsAppMessageId,
                'status' => $statusName,
            ]);

            return;
        }

        $occurredAt = now();
        $rawTimestamp = $statusPayload['timestamp'] ?? null;
        if (is_numeric($rawTimestamp)) {
            $timestamp = (int) $rawTimestamp;
            if ($timestamp > 9999999999) {
                $timestamp = (int) floor($timestamp / 1000);
            }
            $occurredAt = now()->setTimestamp($timestamp);
        }

        $beforeStatus = (string) ($message->delivery_status ?: 'pending');
        switch ($statusName) {
            case 'sent':
                $message->delivery_status = 'sent';
                $message->sent_at = $message->sent_at ?: $occurredAt;
                $message->status_error = null;
                break;
            case 'delivered':
                $message->delivery_status = 'delivered';
                $message->delivered_at = $message->delivered_at ?: $occurredAt;
                $message->status_error = null;
                break;
            case 'read':
                $message->delivery_status = 'read';
                $message->read_at = $message->read_at ?: $occurredAt;
                $message->status_error = null;
                break;
            case 'failed':
                $message->delivery_status = 'failed';
                $message->failed_at = $message->failed_at ?: $occurredAt;
                $message->status_error = $this->formatStatusError($statusPayload['errors'] ?? null);
                break;
            default:
                Log::info('Webhook status ignorado por status nao mapeado.', [
                    'company_id' => (int) $company->id,
                    'message_id' => (int) $message->id,
                    'whatsapp_message_id' => $whatsAppMessageId,
                    'status' => $statusName,
                ]);

                return;
        }

        $message->status_meta = [
            'source' => 'webhook_status',
            'status' => $statusPayload,
            'metadata' => $metadata,
        ];

        if (! $message->isDirty()) {
            return;
        }

        $message->save();

        Log::info('Webhook status aplicado em mensagem.', [
            'company_id' => (int) $company->id,
            'message_id' => (int) $message->id,
            'conversation_id' => (int) $message->conversation_id,
            'whatsapp_message_id' => $whatsAppMessageId,
            'from_status' => $beforeStatus,
            'to_status' => (string) $message->delivery_status,
        ]);
    }

    private function formatStatusError(mixed $errors): ?string
    {
        if ($errors === null) {
            return null;
        }

        if (is_string($errors)) {
            $trimmed = trim($errors);

            return $trimmed !== '' ? $trimmed : null;
        }

        if (is_scalar($errors)) {
            return (string) $errors;
        }

        if (is_array($errors) && isset($errors[0])) {
            $first = $errors[0];
            if (is_array($first)) {
                $title = trim((string) ($first['title'] ?? ''));
                $message = trim((string) ($first['message'] ?? ''));
                $code = trim((string) ($first['code'] ?? ''));
                $summary = trim(implode(' - ', array_filter([$title, $message, $code])));
                if ($summary !== '') {
                    return $summary;
                }
            }
        }

        $encoded = json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded !== false ? $encoded : 'whatsapp_status_failed';
    }
}
