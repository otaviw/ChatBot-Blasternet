<?php

declare(strict_types=1);


namespace App\Services\WhatsApp\Concerns;

use App\Models\Company;
use App\Support\LogSanitizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

trait WhatsAppApiHelpers
{
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
            'company_id'      => $company?->id,
            'type'            => $type,
            'url'             => $url,
            'phone_number_id' => LogSanitizer::maskToken($phoneNumberId),
            'to'              => LogSanitizer::maskPhone($to),
            'to_length'       => strlen($to),
        ]);
    }

    private function logResponseDiagnostics(string $type, Response $response): void
    {
        $payload = [
            'type'             => $type,
            'status'           => $response->status(),
            'success'          => $response->successful(),
            'graph_message_id' => $response->json('messages.0.id'),
            'error'            => $response->json('error.message') ?? $response->json('error.code'),
        ];

        if ($response->successful()) {
            Log::info('WhatsApp API response diagnostico.', $payload);

            return;
        }

        Log::warning('WhatsApp API response diagnostico com erro.', $payload);
    }

    /** @return array<mixed>|null */
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
     * @return array{ok:bool,whatsapp_message_id:?string,status:'sent'|'failed',error:mixed,response:array<mixed>|null}
     */
    private function successResult(?string $whatsappMessageId, ?array $response = null): array
    {
        return [
            'ok'                  => true,
            'whatsapp_message_id' => $whatsappMessageId,
            'status'              => 'sent',
            'error'               => null,
            'response'            => $response,
        ];
    }

    /**
     * @param  array<mixed>|null  $response
     * @return array{ok:bool,whatsapp_message_id:?string,status:'sent'|'failed',error:mixed,response:array<mixed>|null}
     */
    private function failedResult(mixed $error, ?array $response = null): array
    {
        return [
            'ok'                  => false,
            'whatsapp_message_id' => null,
            'status'              => 'failed',
            'error'               => $error,
            'response'            => $response,
        ];
    }

    /**
     * @param  array<mixed>|null  $responseJson
     * @return array{category:string,code:string,message:string,http_status:int|null,meta_code:int|null,retryable:bool,raw_error:mixed}
     */
    private function normalizeMetaApiError(Response $response, ?array $responseJson): array
    {
        $status = $response->status();
        $error = $response->json('error');
        $metaCode = is_array($error) ? (int) ($error['code'] ?? 0) : 0;
        $subcode = is_array($error) ? (int) ($error['error_subcode'] ?? 0) : 0;
        $message = trim((string) ($error['message'] ?? 'Falha ao enviar mensagem para WhatsApp.'));

        $category = 'unknown';
        $code = 'META_API_UNEXPECTED_RESPONSE';
        $retryable = false;

        if ($status === 401 || $metaCode === 190) {
            $category = 'auth';
            $code = 'META_API_TOKEN_INVALID';
        } elseif ($status === 403 || $metaCode === 10 || $metaCode === 200) {
            $category = 'permission';
            $code = 'META_API_PERMISSION_DENIED';
        } elseif ($status === 429 || $metaCode === 4 || $metaCode === 80007 || $metaCode === 130429) {
            $category = 'rate_limit';
            $code = 'META_API_RATE_LIMIT';
            $retryable = true;
        } elseif ($status >= 500 && $status <= 599) {
            $category = 'server';
            $code = 'META_API_SERVER_ERROR';
            $retryable = true;
        } elseif ($metaCode === 131026) {
            $category = 'recipient';
            $code = 'META_API_RECIPIENT_NOT_ALLOWED';
        } elseif ($metaCode === 132000 || $metaCode === 132001 || $metaCode === 132015 || $metaCode === 132016 || $subcode === 2494073) {
            $category = 'template';
            $code = 'META_API_TEMPLATE_REJECTED';
        } elseif ($metaCode === 131052 || $metaCode === 131053) {
            $category = 'media';
            $code = 'META_API_MEDIA_FAILED';
        }

        if (! is_array($error) && $responseJson === null) {
            $message = 'Resposta inesperada da API do WhatsApp.';
        }

        return [
            'category' => $category,
            'code' => $code,
            'message' => $message,
            'http_status' => $status > 0 ? $status : null,
            'meta_code' => $metaCode > 0 ? $metaCode : null,
            'retryable' => $retryable,
            'raw_error' => $error ?? $responseJson ?? $response->body(),
        ];
    }

    /**
     * @return array{category:string,code:string,message:string,http_status:int|null,meta_code:int|null,retryable:bool,raw_error:mixed}
     */
    private function normalizeMetaConnectionError(ConnectionException $exception): array
    {
        return [
            'category' => 'timeout',
            'code' => 'META_API_CONNECTION_ERROR',
            'message' => trim($exception->getMessage()) !== ''
                ? $exception->getMessage()
                : 'Falha de conexão com a API do WhatsApp.',
            'http_status' => null,
            'meta_code' => null,
            'retryable' => true,
            'raw_error' => 'connection_exception',
        ];
    }
}
