<?php

namespace App\Http\Controllers;

use App\Models\Company;
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

        // Log de STATUS (sent/delivered/read/failed)
        if (!empty($value['statuses'])) {
            foreach (($value['statuses'] ?? []) as $st) {
                Log::warning('WA STATUS', [
                    'status' => $st['status'] ?? null,
                    'id' => $st['id'] ?? null,
                    'timestamp' => $st['timestamp'] ?? null,
                    'recipient_id' => $st['recipient_id'] ?? null,
                    'errors' => $st['errors'] ?? null,
                    'conversation' => $st['conversation'] ?? null,
                    'pricing' => $st['pricing'] ?? null,
                    'metadata_phone_number_id' => $value['metadata']['phone_number_id'] ?? null,
                ]);
            }
        } else {
            Log::info('WA STATUS (nenhum status no payload)', [
                'metadata_phone_number_id' => $value['metadata']['phone_number_id'] ?? null,
                'keys' => array_keys($value),
            ]);
        }

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
}
