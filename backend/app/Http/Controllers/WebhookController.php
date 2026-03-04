<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\InboundMessageService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WebhookController extends Controller
{
    public function __construct(
        private InboundMessageService $inboundMessage
    ) {}

    /**
     * Verificação do webhook (GET). Meta envia hub.mode, hub.verify_token, hub.challenge.
     * Configure WHATSAPP_VERIFY_TOKEN no .env com o mesmo valor do painel Meta.
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub.mode');
        $token = $request->query('hub.verify_token');
        $challenge = $request->query('hub.challenge');

        $expectedToken = config('whatsapp.verify_token');

        if ($mode === 'subscribe' && $token === $expectedToken && $challenge) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('', 403);
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
            if (($msg['type'] ?? '') !== 'text') {
                continue;
            }
            $from = (string) ($msg['from'] ?? '');
            $text = (string) ($msg['text']['body'] ?? '');
            $messageId = (string) ($msg['id'] ?? '');
            $contactName = $contactNameByWaId[$from] ?? null;

            if (trim($text) === '' || trim($from) === '') {
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
        }
    }

    /** Encontra company pelo meta_phone_number_id ou retorna primeira; depois pode usar env. */
    private function resolveCompany(string $phoneNumberId): ?Company
    {
        if ($phoneNumberId !== '') {
            $company = Company::with('botSetting')
                ->where('meta_phone_number_id', $phoneNumberId)
                ->first();
            if ($company) {
                return $company;
            }
        }

        return null;
    }
}
