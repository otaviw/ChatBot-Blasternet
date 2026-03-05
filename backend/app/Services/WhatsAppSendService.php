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

    public function sendImage(?Company $company, string $toPhone, string $imageUrl, ?string $caption = null): bool
    {
        $phoneNumberId = $company?->meta_phone_number_id ?: config('whatsapp.phone_number_id');
        $accessToken = $company?->meta_access_token ?: config('whatsapp.access_token');
        $normalizedUrl = trim($imageUrl);
        if ($normalizedUrl !== '' && str_starts_with($normalizedUrl, '/')) {
            $normalizedUrl = rtrim((string) config('app.url'), '/').$normalizedUrl;
        }

        if ($normalizedUrl === '') {
            Log::warning('WhatsApp envio de imagem ignorado: URL vazia.', [
                'to' => $toPhone,
            ]);

            return false;
        }

        if (empty($phoneNumberId) || empty($accessToken)) {
            Log::info('WhatsApp [esqueleto]: envio de imagem simulado (sem token/number_id).', [
                'to' => $toPhone,
                'image_url' => $normalizedUrl,
                'caption' => $caption,
            ]);

            return true;
        }

        $url = rtrim(config('whatsapp.api_url'), '/') . '/' . $phoneNumberId . '/messages';
        $image = ['link' => $normalizedUrl];
        $captionValue = trim((string) $caption);
        if ($captionValue !== '') {
            $image['caption'] = $captionValue;
        }

        $body = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => preg_replace('/\D/', '', $toPhone),
            'type' => 'image',
            'image' => $image,
        ];

        $response = Http::withToken($accessToken)->post($url, $body);

        if (! $response->successful()) {
            Log::warning('WhatsApp API erro ao enviar imagem.', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * @return array{binary:string,mime_type:?string,size_bytes:?int}|null
     */
    public function downloadInboundImage(?Company $company, string $mediaId): ?array
    {
        $accessToken = $company?->meta_access_token ?: config('whatsapp.access_token');
        $normalizedMediaId = trim($mediaId);
        if ($normalizedMediaId === '' || empty($accessToken)) {
            return null;
        }

        $baseUrl = rtrim((string) config('whatsapp.api_url'), '/');
        $metadataUrl = $baseUrl.'/'.$normalizedMediaId;

        $metadataResponse = Http::withToken($accessToken)->get($metadataUrl);
        if (! $metadataResponse->successful()) {
            Log::warning('Falha ao obter metadata de media no WhatsApp.', [
                'media_id' => $normalizedMediaId,
                'status' => $metadataResponse->status(),
            ]);

            return null;
        }

        $metadata = $metadataResponse->json();
        $downloadUrl = trim((string) ($metadata['url'] ?? ''));
        if ($downloadUrl === '') {
            return null;
        }

        $mediaResponse = Http::withToken($accessToken)->get($downloadUrl);
        if (! $mediaResponse->successful()) {
            Log::warning('Falha ao baixar media do WhatsApp.', [
                'media_id' => $normalizedMediaId,
                'status' => $mediaResponse->status(),
            ]);

            return null;
        }

        return [
            'binary' => (string) $mediaResponse->body(),
            'mime_type' => $metadata['mime_type'] ?? $mediaResponse->header('Content-Type'),
            'size_bytes' => isset($metadata['file_size']) ? (int) $metadata['file_size'] : strlen((string) $mediaResponse->body()),
        ];
    }
}
