<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware que valida a assinatura HMAC-SHA256 dos webhooks da Meta/WhatsApp.
 *
 * A Meta envia o header X-Hub-Signature-256: sha256=<hash> em todo POST de webhook.
 * A assinatura é calculada com o payload raw e o WHATSAPP_APP_SECRET como chave.
 *
 * IMPORTANTE: este middleware lê o body raw via getContent(). Deve ser aplicado
 * ANTES de qualquer middleware que consuma o stream do body (não há nenhum assim
 * no stack atual, mas vale registrar).
 *
 * Rejeições retornam 403 sem detalhe ao cliente para não vazar informação.
 * Toda rejeição gera um log warning com contexto suficiente para investigação.
 */
class ValidateWhatsAppWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = [
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent() ?? '-',
            'path'       => $request->path(),
        ];

        // Configuração ausente é um erro nosso, não do cliente
        $secret = (string) config('whatsapp.app_secret', '');
        if ($secret === '') {
            Log::error('Webhook WhatsApp bloqueado: WHATSAPP_APP_SECRET não configurado.', $context);

            return response('Internal configuration error', 500);
        }

        $header = (string) (
            $request->header('X-Hub-Signature-256')
            ?? $request->header('X-Hub-Signature')
            ?? ''
        );

        if ($header === '') {
            Log::warning('Webhook WhatsApp rejeitado: header de assinatura ausente.', $context);

            return response('', 403);
        }

        // Header vem como "sha256=<hex>" — extrai só o hash
        $provided = $header;
        if (str_contains($header, '=')) {
            [, $provided] = explode('=', $header, 2);
        }
        $provided = (string) $provided;

        $rawBody = (string) $request->getContent();
        $expected = hash_hmac('sha256', $rawBody, $secret);

        // Comprimento diferente => hash de algoritmo diferente ou truncado
        if (strlen($provided) !== strlen($expected)) {
            Log::warning('Webhook WhatsApp rejeitado: tamanho de assinatura inválido.', array_merge($context, [
                'provided_length' => strlen($provided),
                'expected_length' => strlen($expected),
                'header_raw'      => substr($header, 0, 20) . '...',
            ]));

            return response('', 403);
        }

        // hash_equals é timing-safe — evita timing attacks
        if (! hash_equals($expected, $provided)) {
            Log::warning('Webhook WhatsApp rejeitado: assinatura invalida.', array_merge($context, [
                // Expõe só os primeiros 8 chars para facilitar debug sem vazar o hash completo
                'provided_hint' => substr($provided, 0, 8) . '...',
            ]));

            return response('', 403);
        }

        return $next($request);
    }
}
