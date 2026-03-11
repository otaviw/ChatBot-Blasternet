<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppSendService
{
    /**
     * Envia texto para um numero WhatsApp.
     * Prioriza credenciais da company e usa env como fallback single-tenant.
     *
     * @return array{
     *     ok:bool,
     *     whatsapp_message_id:?string,
     *     status:'sent'|'failed',
     *     error:mixed,
     *     response:array<mixed>|null
     * }
     */
    public function sendText(?Company $company, string $toPhone, string $text): array
    {
        $phoneNumberId = $this->resolvePhoneNumberId($company);
        $accessToken = $this->resolveAccessToken($company);
        $normalizedTo = $this->normalizeRecipient($toPhone);

        if ($normalizedTo === '') {
            Log::warning('WhatsApp envio de texto ignorado: destinatario invalido.', [
                'company_id' => $company?->id,
                'to_original' => $toPhone,
            ]);

            return $this->failedResult('destinatario_invalido');
        }

        if ($phoneNumberId === '' || $accessToken === '') {
            Log::info('WhatsApp [esqueleto]: envio simulado (sem token/number_id).', [
                'company_id' => $company?->id,
                'phone_number_id' => $phoneNumberId !== '' ? $phoneNumberId : null,
                'to' => $normalizedTo,
                'text' => $text,
            ]);

            return $this->successResult(null, [
                'simulated' => true,
            ]);
        }

        $url = $this->messagesUrl($phoneNumberId);
        $body = [
            'messaging_product' => 'whatsapp',
            'to' => $normalizedTo,
            'type' => 'text',
            'text' => ['body' => $text],
        ];

        $this->logRequestDiagnostics($company, 'text', $url, $phoneNumberId, $normalizedTo, $accessToken);

        /** @var Response $response */
        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->asJson()
            ->post($url, $body);

        $this->logResponseDiagnostics('text', $response);
        $responseJson = $this->responseJson($response);
        $graphMessageId = $this->normalizeGraphMessageId($response->json('messages.0.id'));

        if (! $response->successful()) {
            Log::warning('WhatsApp API erro ao enviar.', [
                'status' => $response->status(),
                'body' => $responseJson,
            ]);

            return $this->failedResult(
                $response->json('error') ?? $responseJson ?? $response->body(),
                $responseJson
            );
        }

        return $this->successResult($graphMessageId, $responseJson);
    }

    /**
     * @return array{
     *     ok:bool,
     *     whatsapp_message_id:?string,
     *     status:'sent'|'failed',
     *     error:mixed,
     *     response:array<mixed>|null
     * }
     */
    public function sendImage(?Company $company, string $toPhone, string $imageUrl, ?string $caption = null): array
    {
        $phoneNumberId = $this->resolvePhoneNumberId($company);
        $accessToken = $this->resolveAccessToken($company);
        $normalizedTo = $this->normalizeRecipient($toPhone);
        $normalizedUrl = trim($imageUrl);
        if ($normalizedUrl !== '' && str_starts_with($normalizedUrl, '/')) {
            $normalizedUrl = rtrim((string) config('app.url'), '/').$normalizedUrl;
        }

        if ($normalizedUrl === '') {
            Log::warning('WhatsApp envio de imagem ignorado: URL vazia.', [
                'to' => $toPhone,
            ]);

            return $this->failedResult('imagem_url_vazia');
        }

        if ($normalizedTo === '') {
            Log::warning('WhatsApp envio de imagem ignorado: destinatario invalido.', [
                'company_id' => $company?->id,
                'to_original' => $toPhone,
            ]);

            return $this->failedResult('destinatario_invalido');
        }

        if ($phoneNumberId === '' || $accessToken === '') {
            Log::info('WhatsApp [esqueleto]: envio de imagem simulado (sem token/number_id).', [
                'company_id' => $company?->id,
                'phone_number_id' => $phoneNumberId !== '' ? $phoneNumberId : null,
                'to' => $normalizedTo,
                'image_url' => $normalizedUrl,
                'caption' => $caption,
            ]);

            return $this->successResult(null, [
                'simulated' => true,
            ]);
        }

        $url = $this->messagesUrl($phoneNumberId);
        $image = ['link' => $normalizedUrl];
        $captionValue = trim((string) $caption);
        if ($captionValue !== '') {
            $image['caption'] = $captionValue;
        }

        $body = [
            'messaging_product' => 'whatsapp',
            'to' => $normalizedTo,
            'type' => 'image',
            'image' => $image,
        ];

        $this->logRequestDiagnostics($company, 'image', $url, $phoneNumberId, $normalizedTo, $accessToken);

        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->asJson()
            ->post($url, $body);

        $this->logResponseDiagnostics('image', $response);
        $responseJson = $this->responseJson($response);
        $graphMessageId = $this->normalizeGraphMessageId($response->json('messages.0.id'));

        if (! $response->successful()) {
            Log::warning('WhatsApp API erro ao enviar imagem.', [
                'status' => $response->status(),
                'body' => $responseJson,
            ]);

            return $this->failedResult(
                $response->json('error') ?? $responseJson ?? $response->body(),
                $responseJson
            );
        }

        return $this->successResult($graphMessageId, $responseJson);
    }

    /**
     * @return array{binary:string,mime_type:?string,size_bytes:?int}|null
     */
    public function downloadInboundImage(?Company $company, string $mediaId): ?array
    {
        $accessToken = $this->resolveAccessToken($company);
        $normalizedMediaId = trim($mediaId);
        if ($normalizedMediaId === '' || $accessToken === '') {
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

    private function resolvePhoneNumberId(?Company $company): string
    {
        $companyPhoneNumberId = trim((string) ($company?->meta_phone_number_id ?? ''));
        if ($companyPhoneNumberId !== '') {
            return $companyPhoneNumberId;
        }

        if ($company?->id) {
            Log::warning('WhatsApp envio com fallback de phone_number_id do env.', [
                'company_id' => $company->id,
            ]);
        }

        return trim((string) config('whatsapp.phone_number_id', ''));
    }

    private function resolveAccessToken(?Company $company): string
    {
        $companyAccessToken = trim((string) ($company?->meta_access_token ?? ''));
        if ($companyAccessToken !== '') {
            return $companyAccessToken;
        }

        return trim((string) config('whatsapp.access_token', ''));
    }

    private function normalizeRecipient(string $to): string
    {
        $normalized = preg_replace('/\D+/', '', (string) $to);

        return trim((string) $normalized);
    }

    private function messagesUrl(string $phoneNumberId): string
    {
        return rtrim((string) config('whatsapp.api_url'), '/').'/'.$phoneNumberId.'/messages';
    }

    private function tokenPrefix(string $token): ?string
    {
        $trimmed = trim($token);
        if ($trimmed === '') {
            return null;
        }

        return substr($trimmed, 0, 12);
    }

    private function logRequestDiagnostics(
        ?Company $company,
        string $type,
        string $url,
        string $phoneNumberId,
        string $to,
        string $accessToken
    ): void {
        Log::info('WhatsApp API request diagnostico.', [
            'company_id' => $company?->id,
            'type' => $type,
            'url' => $url,
            'phone_number_id' => $phoneNumberId,
            'to' => $to,
            'to_length' => strlen($to),
            'token_prefix' => $this->tokenPrefix($accessToken),
        ]);
    }

    private function logResponseDiagnostics(string $type, Response $response): void
    {
        $payload = [
            'type' => $type,
            'status' => $response->status(),
            'success' => $response->successful(),
            'graph_message_id' => $response->json('messages.0.id'),
            'error' => $response->json('error'),
        ];

        if ($response->successful()) {
            Log::info('WhatsApp API response diagnostico.', $payload);

            return;
        }

        Log::warning('WhatsApp API response diagnostico com erro.', $payload);
    }

    /**
     * @return array<mixed>|null
     */
    private function responseJson(Response $response): ?array
    {
        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    private function normalizeGraphMessageId(mixed $value): ?string
    {
        $id = trim((string) $value);

        return $id !== '' ? $id : null;
    }

    /**
     * @param  array<mixed>|null  $response
     * @return array{
     *     ok:bool,
     *     whatsapp_message_id:?string,
     *     status:'sent'|'failed',
     *     error:mixed,
     *     response:array<mixed>|null
     * }
     */
    private function successResult(?string $whatsappMessageId, ?array $response = null): array
    {
        return [
            'ok' => true,
            'whatsapp_message_id' => $whatsappMessageId,
            'status' => 'sent',
            'error' => null,
            'response' => $response,
        ];
    }

    /**
     * @param  array<mixed>|null  $response
     * @return array{
     *     ok:bool,
     *     whatsapp_message_id:?string,
     *     status:'sent'|'failed',
     *     error:mixed,
     *     response:array<mixed>|null
     * }
     */
    private function failedResult(mixed $error, ?array $response = null): array
    {
        return [
            'ok' => false,
            'whatsapp_message_id' => null,
            'status' => 'failed',
            'error' => $error,
            'response' => $response,
        ];
    }
}
