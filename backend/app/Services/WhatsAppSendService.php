<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppSendService
{
    /**
     * Envia texto para um número WhatsApp.
     * Se não houver token/number_id (env ou company), só registra em log e retorna.
     * Quando preencher WHATSAPP_ACCESS_TOKEN e WHATSAPP_PHONE_NUMBER_ID (ou na company), passa a enviar de verdade.
     */
    public function sendText(?Company $company, string $toPhone, string $text): bool
    {
        $phoneNumberId = $company?->meta_phone_number_id ?: config('whatsapp.phone_number_id');
        $accessToken = $company?->meta_access_token ?: config('whatsapp.access_token');

        if (empty($phoneNumberId) || empty($accessToken)) {
            Log::info('WhatsApp [esqueleto]: envio simulado (sem token/number_id).', [
                'to' => $toPhone,
                'text' => $text,
            ]);

            return true;
        }

        $url = rtrim(config('whatsapp.api_url'), '/') . '/' . $phoneNumberId . '/messages';
        $body = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => preg_replace('/\D/', '', $toPhone),
            'type' => 'text',
            'text' => ['body' => $text],
        ];

        /** @var Response $response */
        $response = Http::withToken($accessToken)
            ->post($url, $body);

        if (! $response->successful()) {
            Log::warning('WhatsApp API erro ao enviar.', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return false;
        }

        return true;
    }
}
