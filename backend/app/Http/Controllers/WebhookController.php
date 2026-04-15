<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppWebhookJob;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * GET /webhooks/whatsapp
     *
     * Verificação do endpoint pelo painel Meta for Developers.
     * A Meta envia hub.mode=subscribe, hub.verify_token e hub.challenge.
     * Respondemos com o challenge em texto puro se o token bater.
     *
     * Nota: a Meta normaliza ponto → underscore nos parâmetros dependendo da versão
     * da SDK e da configuração do servidor. Os fallbacks com query() direto existem
     * exatamente por isso — já queimou verificação em produção sem eles.
     */
    public function verify(Request $request): Response
    {
        $mode      = $request->input('hub_mode')         ?? $request->query('hub.mode');
        $token     = $request->input('hub_verify_token') ?? $request->query('hub.verify_token');
        $challenge = $request->input('hub_challenge')    ?? $request->query('hub.challenge');

        $context = [
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent() ?? '-',
            'mode'       => $mode,
        ];

        $expectedToken = (string) config('whatsapp.verify_token');

        if ($mode !== 'subscribe') {
            Log::warning('Webhook WhatsApp: verificacao com mode inválido.', $context);

            return response('Forbidden', 403);
        }

        if ($token !== $expectedToken) {
            Log::warning('Webhook WhatsApp: verify_token inválido.', $context);

            return response('Forbidden', 403);
        }

        Log::info('Webhook WhatsApp: endpoint verificado com sucesso.', $context);

        return response((string) $challenge, 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * POST /webhooks/whatsapp
     *
     * Recebe eventos da Meta/WhatsApp.
     * A assinatura HMAC-SHA256 já foi validada pelo middleware
     * ValidateWhatsAppWebhookSignature antes de chegar aqui.
     *
     * Retorna 200 para qualquer payload bem-assinado, mesmo que não seja
     * whatsapp_business_account. A Meta retentar indefinidamente quando
     * recebe status diferente de 2xx.
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

                ProcessWhatsAppWebhookJob::dispatch($change['value'] ?? [], null);
            }
        }

        return response('', 200);
    }
}
