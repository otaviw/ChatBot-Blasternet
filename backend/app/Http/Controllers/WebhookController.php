<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Services\InboundMessageService;
use App\Services\RealtimePublisher;
use App\Support\MessageDeliveryStatus;
use App\Support\RealtimeEvents;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        private InboundMessageService $inboundMessage,
        private RealtimePublisher $realtimePublisher
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

            if ($messageType === 'reaction') {
                $this->processReaction($company, $msg);

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

        $beforeStatus = (string) ($message->delivery_status ?: MessageDeliveryStatus::PENDING);
        switch ($statusName) {
            case MessageDeliveryStatus::SENT:
                $message->delivery_status = MessageDeliveryStatus::SENT;
                $message->sent_at = $message->sent_at ?: $occurredAt;
                $message->status_error = null;
                break;
            case MessageDeliveryStatus::DELIVERED:
                $message->delivery_status = MessageDeliveryStatus::DELIVERED;
                $message->delivered_at = $message->delivered_at ?: $occurredAt;
                $message->status_error = null;
                break;
            case MessageDeliveryStatus::READ:
                $message->delivery_status = MessageDeliveryStatus::READ;
                $message->read_at = $message->read_at ?: $occurredAt;
                $message->status_error = null;
                break;
            case MessageDeliveryStatus::FAILED:
                $message->delivery_status = MessageDeliveryStatus::FAILED;
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

    /**
     * @param  array<string, mixed>  $messagePayload
     */
    private function processReaction(Company $company, array $messagePayload): void
    {
        $reactorPhone = $this->normalizePhone((string) ($messagePayload['from'] ?? ''));
        $reactionPayload = is_array($messagePayload['reaction'] ?? null)
            ? $messagePayload['reaction']
            : [];
        $targetWhatsappMessageId = trim((string) ($reactionPayload['message_id'] ?? ''));
        $emoji = trim((string) ($reactionPayload['emoji'] ?? ''));

        if ($reactorPhone === '' || $targetWhatsappMessageId === '') {
            Log::warning('Webhook reaction ignorado por payload incompleto.', [
                'company_id' => (int) $company->id,
                'from' => $messagePayload['from'] ?? null,
                'reaction_message_id' => $reactionPayload['message_id'] ?? null,
                'reaction_emoji' => $reactionPayload['emoji'] ?? null,
            ]);

            return;
        }

        $message = Message::query()
            ->where('whatsapp_message_id', $targetWhatsappMessageId)
            ->whereHas('conversation', function ($query) use ($company) {
                $query->where('company_id', (int) $company->id);
            })
            ->first();

        if (! $message) {
            Log::warning('Webhook reaction recebido sem mensagem correspondente.', [
                'company_id' => (int) $company->id,
                'reactor_phone' => $reactorPhone,
                'target_whatsapp_message_id' => $targetWhatsappMessageId,
                'message_type' => $messagePayload['type'] ?? null,
                'message_id' => $messagePayload['id'] ?? null,
            ]);

            return;
        }

        if ($emoji === '') {
            $reaction = MessageReaction::query()
                ->where('message_id', (int) $message->id)
                ->where('reactor_phone', $reactorPhone)
                ->first();

            if (! $reaction) {
                return;
            }

            $reaction->delete();
            $this->publishMessageReactionsUpdated($company, $message);

            return;
        }

        MessageReaction::updateOrCreate(
            [
                'message_id' => (int) $message->id,
                'reactor_phone' => $reactorPhone,
            ],
            [
                'whatsapp_message_id' => $targetWhatsappMessageId,
                'emoji' => $emoji,
                'reacted_at' => $this->resolveMetaTimestamp($messagePayload['timestamp'] ?? null),
                'meta' => [
                    'source' => 'webhook_reaction',
                    'message_id' => $messagePayload['id'] ?? null,
                    'reaction' => $reactionPayload,
                ],
            ]
        );

        $this->publishMessageReactionsUpdated($company, $message);
    }

    private function publishMessageReactionsUpdated(Company $company, Message $message): void
    {
        $reactions = MessageReaction::query()
            ->where('message_id', (int) $message->id)
            ->orderBy('id')
            ->get(['reactor_phone', 'emoji', 'reacted_at'])
            ->map(function (MessageReaction $reaction): array {
                return [
                    'reactor_phone' => (string) $reaction->reactor_phone,
                    'emoji' => (string) ($reaction->emoji ?? ''),
                    'reacted_at' => $reaction->reacted_at?->toISOString(),
                ];
            })
            ->values()
            ->all();

        $this->realtimePublisher->publish(
            RealtimeEvents::MESSAGE_REACTIONS_UPDATED,
            [
                "company:{$company->id}",
                "conversation:{$message->conversation_id}",
            ],
            [
                'conversation_id' => (int) $message->conversation_id,
                'message_id' => (int) $message->id,
                'reactions' => $reactions,
            ]
        );
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone) ?? '';
    }

    private function resolveMetaTimestamp(mixed $rawTimestamp): ?\Illuminate\Support\Carbon
    {
        if (! is_numeric($rawTimestamp)) {
            return null;
        }

        $timestamp = (int) $rawTimestamp;
        if ($timestamp > 9999999999) {
            $timestamp = (int) floor($timestamp / 1000);
        }

        return now()->setTimestamp($timestamp);
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
