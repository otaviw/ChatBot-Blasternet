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

        $this->logRequestDiagnostics($company, 'text', $url, $phoneNumberId, $normalizedTo);

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
            $normalizedUrl = rtrim((string) config('app.url'), '/') . $normalizedUrl;
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

        $this->logRequestDiagnostics($company, 'image', $url, $phoneNumberId, $normalizedTo);

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
     * 1. Upload arquivo local pra Meta (obrigatório pra mídia outbound).
     * @return array{id: string}|null
     */
    public function uploadMedia(?Company $company, string $binaryData, string $mimeType, ?string $filename = null): ?array
    {
        $accessToken = $this->resolveAccessToken($company);
        $phoneNumberId = $this->resolvePhoneNumberId($company);
        if (!$accessToken || !$phoneNumberId) {
            Log::warning('Upload mídia falhou: sem token/phone_id');
            return null;
        }

        $url = rtrim(config('whatsapp.api_url'), '/') . "/{$phoneNumberId}/media";

        $response = Http::withToken($accessToken)
            ->attach('filedata', $binaryData, $filename ?: 'file', $mimeType)  // Meta usa 'filedata'
            ->post($url);

        if (!$response->successful()) {
            Log::warning('Upload mídia falhou', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        }

        $id = $response->json('id');
        Log::info('Upload mídia sucesso', ['media_id' => $id]);
        return ['id' => $id];
    }

    /**
     * 2. Envia qualquer mídia (image/video/audio/document) via media_id.
     */
    public function sendMedia(?Company $company, string $toPhone, string $mediaId, string $type, ?string $caption = null): array
    {
        $phoneNumberId = $this->resolvePhoneNumberId($company);
        $accessToken = $this->resolveAccessToken($company);
        $normalizedTo = $this->normalizeRecipient($toPhone);

        if (!$phoneNumberId || !$accessToken || !$normalizedTo) {
            return $this->failedResult('config_invalida');
        }

        $url = $this->messagesUrl($phoneNumberId);
        $media = ['id' => $mediaId];
        $body = [
            'messaging_product' => 'whatsapp',
            'to' => $normalizedTo,
            'type' => $type,  // 'image', 'video', 'audio', 'document'
            $type => $media,
        ];
        if ($caption) $body[$type]['caption'] = $caption;

        $this->logRequestDiagnostics($company, $type, $url, $phoneNumberId, $normalizedTo);

        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->asJson()
            ->post($url, $body);

        $this->logResponseDiagnostics($type, $response);
        $responseJson = $this->responseJson($response);
        $graphMessageId = $this->normalizeGraphMessageId($response->json('messages.0.id') ?? null);

        if (!$response->successful()) {
            Log::warning('Send mídia falhou', ['status' => $response->status(), 'body' => $responseJson]);
            return $this->failedResult($response->json('error') ?? $responseJson, $responseJson);
        }

        Log::info('Send mídia sucesso', ['whatsapp_message_id' => $graphMessageId]);
        return $this->successResult($graphMessageId, $responseJson);
    }

    /**
     * Envia mídia de forma inteligente: 
     * - Se tem token/phone_id → upload Meta real
     * - Se não → simulado local (ok=true)
     */
    public function sendMediaFile(?Company $company, string $toPhone, string $filePath, string $mimeType, string $type, ?string $caption = null, ?string $filename = null): array
    {
        $phoneNumberId = $this->resolvePhoneNumberId($company);
        $accessToken = $this->resolveAccessToken($company);
        $normalizedTo = $this->normalizeRecipient($toPhone);

        if (!$phoneNumberId || !$accessToken || !$normalizedTo) {
            Log::info('Envio local simulado (sem config Meta).', [
                'file' => basename($filePath),
                'type' => $type,
            ]);
            return [
                'ok' => true,
                'whatsapp_message_id' => null,
                'status' => 'sent',
                'error' => null,
                'response' => ['simulated' => true, 'type' => $type],
            ];
        }

        $fileExists = file_exists($filePath);
        $fileSize = $fileExists ? filesize($filePath) : 0;

        Log::info('sendMediaFile: verificando arquivo.', [
            'path' => $filePath,
            'exists' => $fileExists,
            'size_bytes' => $fileSize,
            'mime' => $mimeType,
            'type' => $type,
        ]);

        if (!$fileExists || $fileSize === 0) {
            Log::error('sendMediaFile: arquivo não encontrado ou vazio.', ['path' => $filePath]);
            return $this->failedResult('arquivo_nao_encontrado');
        }

        $fileBinary = file_get_contents($filePath);
        if ($fileBinary === false || $fileBinary === '') {
            Log::error('sendMediaFile: não foi possível ler o arquivo.', ['path' => $filePath]);
            return $this->failedResult('arquivo_nao_lido');
        }

        $uploadUrl = rtrim(config('whatsapp.api_url'), '/') . "/{$phoneNumberId}/media";

        // ✅ CORREÇÃO AQUI
        $uploadResponse = Http::withToken($accessToken)
            ->attach(
                'file',
                $fileBinary,
                $filename ?: basename($filePath),
                $mimeType
            )
            ->post($uploadUrl, [
                'messaging_product' => 'whatsapp'
            ]);

        Log::info('Upload response', [
            'status' => $uploadResponse->status(),
            'body' => $uploadResponse->json()
        ]);

        if (!$uploadResponse->successful()) {
            return $this->failedResult('upload_falhou', $this->responseJson($uploadResponse));
        }

        $mediaId = $uploadResponse->json('id');
        if (!$mediaId) {
            return $this->failedResult('media_id_invalido');
        }

        $sendUrl = $this->messagesUrl($phoneNumberId);

        $body = [
            'messaging_product' => 'whatsapp',
            'to' => $normalizedTo,
            'type' => $type,
            $type => [
                'id' => $mediaId,
                'filename' => $filename ?: basename($filePath) // ✅ importante pra document
            ],
        ];

        if ($caption) {
            $body[$type]['caption'] = trim((string) $caption);
        }

        $sendResponse = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->asJson()
            ->post($sendUrl, $body);

        if (!$sendResponse->successful()) {
            Log::error('Envio falhou', [
                'status' => $sendResponse->status(),
                'body' => $sendResponse->json()
            ]);

            return $this->failedResult('envio_falhou', $this->responseJson($sendResponse));
        }

        $graphMessageId = $this->normalizeGraphMessageId($sendResponse->json('messages.0.id') ?? null);

        return $this->successResult($graphMessageId, $this->responseJson($sendResponse));
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
        $metadataUrl = $baseUrl . '/' . $normalizedMediaId;

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
        return rtrim((string) config('whatsapp.api_url'), '/') . '/' . $phoneNumberId . '/messages';
    }

    private function logRequestDiagnostics(
        ?Company $company,
        string $type,
        string $url,
        string $phoneNumberId,
        string $to
    ): void {
        Log::info('WhatsApp API request diagnostico.', [
            'company_id' => $company?->id,
            'type' => $type,
            'url' => $url,
            'phone_number_id' => $phoneNumberId,
            'to' => $to,
            'to_length' => strlen($to),
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
