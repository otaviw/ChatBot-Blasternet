<?php

namespace App\Services\WhatsApp;

use App\Models\Company;
use App\Services\WhatsApp\Concerns\WhatsAppApiHelpers;
use App\Support\Enums\MessageType;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MediaMessageHandler
{
    use WhatsAppApiHelpers;

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

        $response = Http::withToken($accessToken)
            ->attach('filedata', $binaryData, $filename ?: 'file', $mimeType)
            ->post($url);

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

        if (! $phoneNumberId || ! $accessToken || ! $normalizedTo) {
            return $this->failedResult('config_invalida');
        }

        $url  = $this->messagesUrl($phoneNumberId);
        $body = [
            'messaging_product' => 'whatsapp',
            'to'                => $normalizedTo,
            'type'              => $type,
            $type               => ['id' => $mediaId],
        ];
        if ($caption) {
            $body[$type]['caption'] = $caption;
        }

        $this->logRequestDiagnostics($company, $type, $url, $phoneNumberId, $normalizedTo);

        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->asJson()
            ->post($url, $body);

        $this->logResponseDiagnostics($type, $response);
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
    public function sendMediaFile(
        ?Company $company,
        string $toPhone,
        string $filePath,
        string $mimeType,
        string $type,
        ?string $caption = null,
        ?string $filename = null
    ): array {
        $phoneNumberId = $this->resolvePhoneNumberId($company);
        $accessToken   = $this->resolveAccessToken($company);
        $normalizedTo  = $this->normalizeRecipient($toPhone);

        if (! $phoneNumberId || ! $accessToken || ! $normalizedTo) {
            Log::info('Envio local simulado (sem config Meta).', [
                'file' => basename($filePath),
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

        $fileBinary = file_get_contents($filePath);
        if ($fileBinary === false || $fileBinary === '') {
            Log::error('sendMediaFile: leitura do arquivo falhou.', ['path' => $filePath]);

            return $this->failedResult('arquivo_nao_lido');
        }

        $uploadUrl = rtrim(config('whatsapp.api_url'), '/') . "/{$phoneNumberId}/media";

        $uploadResponse = Http::withToken($accessToken)
            ->attach('file', $fileBinary, $filename ?: basename($filePath), ['Content-Type' => $mimeType])
            ->post($uploadUrl, [
                'messaging_product' => 'whatsapp',
                'type'              => $mimeType,
            ]);

        if (! $uploadResponse->successful()) {
            return $this->failedResult('upload_falhou', $this->responseJson($uploadResponse));
        }

        $mediaId = $uploadResponse->json('id');
        if (! $mediaId) {
            return $this->failedResult('media_id_invalido');
        }

        $sendUrl = $this->messagesUrl($phoneNumberId);
        $body    = [
            'messaging_product' => 'whatsapp',
            'to'                => $normalizedTo,
            'type'              => $type,
            $type               => ['id' => $mediaId],
        ];
        if ($caption) {
            $body[$type]['caption'] = trim((string) $caption);
        }

        $sendResponse = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->asJson()
            ->post($sendUrl, $body);

        if (! $sendResponse->successful()) {
            return $this->failedResult('envio_falhou', $this->responseJson($sendResponse));
        }

        $graphMessageId = $this->normalizeGraphMessageId($sendResponse->json('messages.0.id') ?? null);

        return $this->successResult($graphMessageId, $this->responseJson($sendResponse));
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
}
