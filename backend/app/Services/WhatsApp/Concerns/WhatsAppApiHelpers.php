<?php

namespace App\Services\WhatsApp\Concerns;

use App\Models\Company;
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
            'phone_number_id' => $phoneNumberId,
            'to'              => $to,
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
            'error'            => $response->json('error'),
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
}
