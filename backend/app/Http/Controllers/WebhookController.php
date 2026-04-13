<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppWebhookJob;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    // a meta manda os parâmetros com ponto (hub.mode) mas o Laravel normaliza para _
    // os fallbacks via query() direto existem porque nem sempre a normalização funciona dependendo
    // de como o servidor está configurado, já queimou uma verificação em produção por isso
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

    // retorna 200 mesmo para payloads que não são whatsapp_business_account porque o Meta
    // espera 200 de qualquer jeito. resposta diferente faz ele retentar e encher o log.
    public function handle(Request $request): Response
    {
        if (! $this->isValidSignature($request)) {
            Log::warning('Webhook WhatsApp com assinatura inválida ou ausente.');

            return response('', 403);
        }

        $body = $request->all();

        if (($body['object'] ?? null) !== 'whatsapp_business_account') {
            return response('', 200);
        }

        foreach ($body['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) !== 'messages') {
                    continue;
                }
                ProcessWhatsAppWebhookJob::dispatch($change['value'] ?? [], null);
            }
        }

        return response('', 200);
    }

    private function isValidSignature(Request $request): bool
    {
        $secret = (string) config('whatsapp.app_secret', '');
        if ($secret === '') {
            Log::error('Webhook WhatsApp rejeitado: WHATSAPP_APP_SECRET nao configurado.');

            return false;
        }

        $header = (string) ($request->header('X-Hub-Signature-256')
            ?? $request->header('X-Hub-Signature')
            ?? '');
        if ($header === '') {
            return false;
        }

        $provided = $header;
        if (str_contains($header, '=')) {
            [, $value] = explode('=', $header, 2);
            $provided = (string) $value;
        }

        $rawBody = $request->getContent();
        $expected = hash_hmac('sha256', $rawBody, $secret);

        if (strlen($provided) !== strlen($expected)) {
            return false;
        }

        return hash_equals($expected, $provided);
    }
}
