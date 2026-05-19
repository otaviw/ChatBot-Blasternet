<?php

declare(strict_types=1);


namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * GET /webhooks/whatsapp
     *
     * Verificacao do endpoint pelo painel Meta for Developers.
     * Aceita verify token global (env) e tambem token por empresa quando configurado.
     */
    public function verify(Request $request): Response
    {
        $mode = $request->input('hub_mode') ?? $request->query('hub.mode');
        $token = $request->input('hub_verify_token') ?? $request->query('hub.verify_token');
        $challenge = $request->input('hub_challenge') ?? $request->query('hub.challenge');

        $context = [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent() ?? '-',
            'mode' => $mode,
        ];

        if ($mode !== 'subscribe') {
            Log::warning('Webhook WhatsApp: verificacao com mode invalido.', $context);

            return response('Forbidden', 403);
        }

        if (! $this->isValidVerifyToken((string) $token)) {
            Log::warning('Webhook WhatsApp: verify_token invalido.', $context);

            return response('Forbidden', 403);
        }

        Log::info('Webhook WhatsApp: endpoint verificado com sucesso.', $context);

        return response((string) $challenge, 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * POST /webhooks/whatsapp
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

    private function isValidVerifyToken(string $providedToken): bool
    {
        $providedToken = trim($providedToken);
        if ($providedToken === '') {
            return false;
        }

        $expectedToken = trim((string) config('whatsapp.verify_token', ''));
        if ($expectedToken !== '' && hash_equals($expectedToken, $providedToken)) {
            return true;
        }

        return Company::query()
            ->whereNotNull('meta_verify_token')
            ->cursor()
            ->contains(function (Company $company) use ($providedToken): bool {
                $companyToken = trim((string) ($company->meta_verify_token ?? ''));

                return $companyToken !== '' && hash_equals($companyToken, $providedToken);
            });
    }
}
