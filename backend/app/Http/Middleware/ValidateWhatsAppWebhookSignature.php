<?php

declare(strict_types=1);


namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware que valida a assinatura HMAC-SHA256 dos webhooks da Meta/WhatsApp.
 *
 * A Meta envia o header X-Hub-Signature-256: sha256=<hash> em todo POST de webhook.
 * A assinatura e calculada com o payload raw e o app secret configurado no app da Meta.
 *
 * Em ambiente multi-tenant tentamos primeiro o app_secret da empresa identificada pelo
 * metadata.phone_number_id do payload; se nao existir, usamos WHATSAPP_APP_SECRET global.
 */
class ValidateWhatsAppWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent() ?? '-',
            'path' => $request->path(),
        ];

        $header = (string) (
            $request->header('X-Hub-Signature-256')
            ?? $request->header('X-Hub-Signature')
            ?? ''
        );

        if ($header === '') {
            Log::warning('Webhook WhatsApp rejeitado: header de assinatura ausente.', $context);

            return response('', 403);
        }

        $provided = $header;
        if (str_contains($header, '=')) {
            [, $provided] = explode('=', $header, 2);
        }
        $provided = (string) $provided;

        $rawBody = (string) $request->getContent();
        $secrets = $this->resolveCandidateSecrets($rawBody);

        if ($secrets === []) {
            Log::error('Webhook WhatsApp bloqueado: nenhum app_secret configurado (company/global).', $context);

            return response('', 403);
        }

        foreach ($secrets as $secret) {
            $expected = hash_hmac('sha256', $rawBody, $secret);
            if (strlen($provided) !== strlen($expected)) {
                continue;
            }

            if (hash_equals($expected, $provided)) {
                return $next($request);
            }
        }

        Log::warning('Webhook WhatsApp rejeitado: assinatura invalida.', array_merge($context, [
            'provided_length' => strlen($provided),
            'header_raw' => substr($header, 0, 20) . '...',
        ]));

        return response('', 403);
    }

    /**
     * @return array<int, string>
     */
    private function resolveCandidateSecrets(string $rawBody): array
    {
        $secrets = [];

        $phoneNumberId = $this->extractPhoneNumberIdFromPayload($rawBody);
        if ($phoneNumberId !== null) {
            $company = Company::query()
                ->where('meta_phone_number_id_hash', Company::phoneNumberIdHash($phoneNumberId))
                ->first(['id', 'meta_app_secret']);

            $companySecret = trim((string) ($company?->meta_app_secret ?? ''));
            if ($companySecret !== '') {
                $secrets[] = $companySecret;
            }
        }

        $globalSecret = trim((string) config('whatsapp.app_secret', ''));
        if ($globalSecret !== '') {
            $secrets[] = $globalSecret;
        }

        return array_values(array_unique(array_filter($secrets, static fn (string $value): bool => $value !== '')));
    }

    private function extractPhoneNumberIdFromPayload(string $rawBody): ?string
    {
        if ($rawBody === '') {
            return null;
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return null;
        }

        $phoneNumberId = trim((string) Arr::get($payload, 'entry.0.changes.0.value.metadata.phone_number_id', ''));

        return $phoneNumberId !== '' ? $phoneNumberId : null;
    }
}
