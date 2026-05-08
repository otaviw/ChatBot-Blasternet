<?php

declare(strict_types=1);


namespace App\Services\WhatsApp;

use App\Models\Company;
use App\Services\WhatsApp\Concerns\WhatsAppApiHelpers;
use App\Support\Enums\MessageType;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MediaMessageHandler
{
    use WhatsAppApiHelpers;

    private const SUPPORTED_MEDIA_TYPES = ['image', 'document', 'audio', 'video', 'sticker'];

    /**
     * @return array{ok:bool,whatsapp_message_id:?string,status:'sent'|'failed',error:mixed,response:array<mixed>|null}
     */
    public function sendImage(?Company $company, string $toPhone, string $imageUrl, ?string $caption = null): array
    {
        $phoneNumberId = $this->resolvePhoneNumberId($company);
        $accessToken   = $this->resolveAccessToken($company);
        $normalizedTo  = $this->normalizeRecipient($toPhone);
        $normalizedUrl = trim($imageUrl);
        if ($normalizedUrl !== '' && str_starts_with($normalizedUrl, '/')) {
            $normalizedUrl = rtrim((string) config('app.url'), '/') . $normalizedUrl;
        }

        if ($normalizedUrl === '') {
            Log::warning('WhatsApp envio de imagem ignorado: URL vazia.', ['to' => $toPhone]);

            return $this->failedResult('imagem_url_vazia');
        }
        if (! $this->isValidMediaUrl($normalizedUrl)) {
            Log::warning('WhatsApp envio de imagem ignorado: URL inválida.', [
                'company_id' => $company?->id,
                'to' => $toPhone,
            ]);

            return $this->failedResult('imagem_url_invalida');
        }

        if ($normalizedTo === '') {
            Log::warning('WhatsApp envio de imagem ignorado: destinatario inválido.', [
                'company_id'  => $company?->id,
                'to_original' => $toPhone,
            ]);

            return $this->failedResult('destinatario_invalido');
        }

        if ($phoneNumberId === '' || $accessToken === '') {
            Log::info('WhatsApp [esqueleto]: envio de imagem simulado (sem token/number_id).', [
                'company_id'      => $company?->id,
                'phone_number_id' => $phoneNumberId !== '' ? $phoneNumberId : null,
                'to'              => $normalizedTo,
                'image_url'       => $normalizedUrl,
                'caption'         => $caption,
            ]);

            return $this->successResult(null, ['simulated' => true]);
        }

        $url   = $this->messagesUrl($phoneNumberId);
        $image = ['link' => $normalizedUrl];
        $captionValue = trim((string) $caption);
        if ($captionValue !== '') {
            $image['caption'] = $captionValue;
        }

        $body = [
            'messaging_product' => 'whatsapp',
            'to'                => $normalizedTo,
            'type'              => MessageType::IMAGE->value,
            'image'             => $image,
        ];

        $this->logRequestDiagnostics($company, MessageType::IMAGE->value, $url, $phoneNumberId, $normalizedTo);

        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->asJson()
            ->post($url, $body);

        $this->logResponseDiagnostics(MessageType::IMAGE->value, $response);
        $responseJson   = $this->responseJson($response);
        $graphMessageId = $this->normalizeGraphMessageId($response->json('messages.0.id'));

        if (! $response->successful()) {
            Log::warning('WhatsApp API erro ao enviar imagem.', [
                'status' => $response->status(),
                'body'   => $responseJson,
            ]);

            return $this->failedResult(
                $response->json('error') ?? $responseJson ?? $response->body(),
                $responseJson
            );
        }

        return $this->successResult($graphMessageId, $responseJson);
    }

    /** @return array{id: string}|null */
    public function uploadMedia(?Company $company, string $binaryData, string $mimeType, ?string $filename = null): ?array
    {
        $accessToken   = $this->resolveAccessToken($company);
        $phoneNumberId = $this->resolvePhoneNumberId($company);
        if (! $accessToken || ! $phoneNumberId) {
            Log::warning('Upload mídia falhou: sem token/phone_id');

            return null;
        }

        $url = rtrim(config('whatsapp.api_url'), '/') . "/{$phoneNumberId}/media";
        $timeoutSeconds = max(5, (int) config('services.whatsapp.timeout', 20));

        $response = Http::withToken($accessToken)
            ->timeout($timeoutSeconds)
            ->attach('file', $binaryData, $filename ?: 'file', ['Content-Type' => $mimeType])
            ->post($url, [
                'messaging_product' => 'whatsapp',
                'type' => $mimeType,
            ]);

        if (! $response->successful()) {
            Log::warning('Upload mídia falhou', ['status' => $response->status(), 'body' => $response->body()]);

            return null;
        }

        $id = $response->json('id');
        Log::info('Upload mídia sucesso', ['media_id' => $id]);

        return ['id' => $id];
    }

    /**
     * @return array{ok:bool,whatsapp_message_id:?string,status:'sent'|'failed',error:mixed,response:array<mixed>|null}
     */
    public function sendMedia(?Company $company, string $toPhone, string $mediaId, string $type, ?string $caption = null): array
    {
        $phoneNumberId = $this->resolvePhoneNumberId($company);
        $accessToken   = $this->resolveAccessToken($company);
        $normalizedTo  = $this->normalizeRecipient($toPhone);
        $normalizedType = trim(strtolower($type));

        if (! $this->isSupportedMediaType($normalizedType)) {
            Log::warning('Send mídia falhou: tipo inválido.', [
                'company_id' => $company?->id,
                'type' => $type,
            ]);

            return $this->failedResult('tipo_midia_invalido');
        }

        if (! $phoneNumberId || ! $accessToken || ! $normalizedTo) {
            Log::warning('Send midia falhou: config invalida.', [
                'company_id' => $company?->id,
                'has_phone_number_id' => $phoneNumberId !== '',
                'has_access_token' => $accessToken !== '',
                'recipient_valid' => $normalizedTo !== '',
                'type' => $type,
            ]);

            return $this->failedResult('config_invalida');
        }

        $url  = $this->messagesUrl($phoneNumberId);
        $body = [
            'messaging_product' => 'whatsapp',
            'to'                => $normalizedTo,
            'type'              => $normalizedType,
            $normalizedType     => ['id' => $mediaId],
        ];
        if ($caption) {
            $body[$normalizedType]['caption'] = $caption;
        }

        $this->logRequestDiagnostics($company, $normalizedType, $url, $phoneNumberId, $normalizedTo);

        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->asJson()
            ->post($url, $body);

        $this->logResponseDiagnostics($normalizedType, $response);
        $responseJson   = $this->responseJson($response);
        $graphMessageId = $this->normalizeGraphMessageId($response->json('messages.0.id') ?? null);

        if (! $response->successful()) {
            Log::warning('Send mídia falhou', ['status' => $response->status(), 'body' => $responseJson]);

            return $this->failedResult($response->json('error') ?? $responseJson, $responseJson);
        }

        Log::info('Send mídia sucesso', ['whatsapp_message_id' => $graphMessageId]);

        return $this->successResult($graphMessageId, $responseJson);
    }

    /**
     * @return array{ok:bool,whatsapp_message_id:?string,status:'sent'|'failed',error:mixed,response:array<mixed>|null}
     */
    public function sendMediaBinary(
        ?Company $company,
        string $toPhone,
        string $binaryData,
        string $mimeType,
        string $type,
        ?string $caption = null,
        ?string $filename = null,
        bool $strict = false
    ): array {
        $phoneNumberId = $this->resolvePhoneNumberId($company);
        $accessToken   = $this->resolveAccessToken($company);
        $normalizedTo  = $this->normalizeRecipient($toPhone);
        $normalizedType = trim(strtolower($type));

        if (! $phoneNumberId || ! $accessToken || ! $normalizedTo) {
            if ($strict) {
                Log::warning('sendMediaBinary: config invalida.', [
                    'company_id' => $company?->id,
                    'has_phone_number_id' => $phoneNumberId !== '',
                    'has_access_token' => $accessToken !== '',
                    'recipient_valid' => $normalizedTo !== '',
                    'type' => $type,
                ]);

                return $this->failedResult('config_invalida');
            }

            Log::info('Envio local simulado (sem config Meta).', [
                'file' => $filename ?: 'file',
                'type' => $type,
            ]);

            return [
                'ok'                  => true,
                'whatsapp_message_id' => null,
                'status'              => 'sent',
                'error'               => null,
                'response'            => ['simulated' => true, 'type' => $type],
            ];
        }

        if (! $this->isSupportedMediaType($normalizedType)) {
            Log::warning('sendMediaBinary: tipo inválido.', [
                'company_id' => $company?->id,
                'type' => $type,
            ]);

            return $this->failedResult('tipo_midia_invalido');
        }

        if ($binaryData === '') {
            Log::error('sendMediaBinary: conteudo vazio.', [
                'company_id' => $company?->id,
                'type' => $normalizedType,
                'mime_type' => $mimeType,
            ]);

            return $this->failedResult('arquivo_nao_lido');
        }
        if (! $this->isMimeTypeAllowedForType($mimeType, $normalizedType)) {
            Log::warning('sendMediaBinary: mime type incompatível com tipo de mídia.', [
                'company_id' => $company?->id,
                'type' => $normalizedType,
                'mime_type' => $mimeType,
            ]);

            return $this->failedResult('mime_type_invalido');
        }
        $maxBytes = $this->resolveMaxUploadBytes();
        if (strlen($binaryData) > $maxBytes) {
            Log::warning('sendMediaBinary: arquivo excede limite de tamanho.', [
                'company_id' => $company?->id,
                'type' => $normalizedType,
                'size_bytes' => strlen($binaryData),
                'max_bytes' => $maxBytes,
            ]);

            return $this->failedResult('arquivo_muito_grande');
        }

        $upload = $this->uploadMedia($company, $binaryData, $mimeType, $filename);
        $mediaId = is_array($upload) ? trim((string) ($upload['id'] ?? '')) : '';
        if ($mediaId === '') {
            return $this->failedResult('upload_falhou');
        }

        return $this->sendMedia($company, $toPhone, $mediaId, $normalizedType, $caption);
    }

    /**
     * @return array{ok:bool,whatsapp_message_id:?string,status:'sent'|'failed',error:mixed,response:array<mixed>|null}
     */
    public function sendMediaFile(
        ?Company $company,
        string $toPhone,
        string $filePath,
        string $mimeType,
        string $type,
        ?string $caption = null,
        ?string $filename = null,
        bool $strict = false
    ): array {
        $fileExists = file_exists($filePath);
        $fileSize   = $fileExists ? (int) filesize($filePath) : 0;

        Log::info('sendMediaFile: verificando arquivo.', [
            'path'       => $filePath,
            'exists'     => $fileExists,
            'size_bytes' => $fileSize,
            'mime'       => $mimeType,
            'type'       => $type,
        ]);

        if (! $fileExists || $fileSize === 0) {
            Log::error('sendMediaFile: arquivo não encontrado ou vazio.', ['path' => $filePath]);

            return $this->failedResult('arquivo_nao_encontrado');
        }
        $maxBytes = $this->resolveMaxUploadBytes();
        if ($fileSize > $maxBytes) {
            Log::warning('sendMediaFile: arquivo excede limite de tamanho.', [
                'path' => $filePath,
                'size_bytes' => $fileSize,
                'max_bytes' => $maxBytes,
            ]);

            return $this->failedResult('arquivo_muito_grande');
        }

        $fileBinary = file_get_contents($filePath);
        if ($fileBinary === false || $fileBinary === '') {
            Log::error('sendMediaFile: leitura do arquivo falhou.', ['path' => $filePath]);

            return $this->failedResult('arquivo_nao_lido');
        }

        return $this->sendMediaBinary(
            $company,
            $toPhone,
            $fileBinary,
            $mimeType,
            $type,
            $caption,
            $filename ?: basename($filePath),
            $strict
        );
    }

    /** @return array{binary:string,mime_type:?string,size_bytes:?int}|null */
    public function downloadInboundImage(?Company $company, string $mediaId): ?array
    {
        $accessToken       = $this->resolveAccessToken($company);
        $normalizedMediaId = trim($mediaId);
        if ($normalizedMediaId === '' || $accessToken === '') {
            return null;
        }

        $baseUrl     = rtrim((string) config('whatsapp.api_url'), '/');
        $metadataUrl = $baseUrl . '/' . $normalizedMediaId;

        $metadataResponse = Http::withToken($accessToken)->get($metadataUrl);
        if (! $metadataResponse->successful()) {
            Log::warning('Falha ao obter metadata de media no WhatsApp.', [
                'media_id' => $normalizedMediaId,
                'status'   => $metadataResponse->status(),
            ]);

            return null;
        }

        $metadata    = $metadataResponse->json();
        $downloadUrl = trim((string) ($metadata['url'] ?? ''));
        if ($downloadUrl === '') {
            return null;
        }

        $mediaResponse = Http::withToken($accessToken)->get($downloadUrl);
        if (! $mediaResponse->successful()) {
            Log::warning('Falha ao baixar media do WhatsApp.', [
                'media_id' => $normalizedMediaId,
                'status'   => $mediaResponse->status(),
            ]);

            return null;
        }

        return [
            'binary'     => (string) $mediaResponse->body(),
            'mime_type'  => $metadata['mime_type'] ?? $mediaResponse->header('Content-Type'),
            'size_bytes' => isset($metadata['file_size'])
                ? (int) $metadata['file_size']
                : strlen((string) $mediaResponse->body()),
        ];
    }

    private function resolveMaxUploadBytes(): int
    {
        $kb = (int) config('whatsapp.media_max_size_kb', 5120);

        return max(1, $kb) * 1024;
    }

    private function isSupportedMediaType(string $type): bool
    {
        return in_array($type, self::SUPPORTED_MEDIA_TYPES, true);
    }

    private function isMimeTypeAllowedForType(string $mimeType, string $type): bool
    {
        $normalizedMime = trim(strtolower($mimeType));
        if ($normalizedMime === '') {
            return false;
        }

        return match ($type) {
            'image' => str_starts_with($normalizedMime, 'image/'),
            'audio' => str_starts_with($normalizedMime, 'audio/'),
            'video' => str_starts_with($normalizedMime, 'video/'),
            'sticker' => in_array($normalizedMime, ['image/webp', 'image/png'], true),
            'document' => true,
            default => false,
        };
    }

    private function isValidMediaUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}
