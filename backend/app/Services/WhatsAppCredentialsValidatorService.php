<?php

declare(strict_types=1);


namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppCredentialsValidatorService
{
    private const TIMEOUT_SECONDS = 10;

    /**
     * Valida as credenciais chamando GET /{phone_number_id} na API da Meta.
     *
     * @return array{ok: bool, display_phone_number: string|null, verified_name: string|null, error: string|null}
     */
    public function validate(string $phoneNumberId, string $accessToken): array
    {
        $phoneNumberId = trim($phoneNumberId);
        $accessToken   = trim($accessToken);

        if ($phoneNumberId === '' || $accessToken === '') {
            return $this->failure('phone_number_id e access_token são obrigatórios.');
        }

        $apiUrl = rtrim((string) config('whatsapp.api_url', 'https://graph.facebook.com/v22.0'), '/');
        $url    = "{$apiUrl}/{$phoneNumberId}";

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withToken($accessToken)
                ->get($url, ['fields' => 'display_phone_number,verified_name,quality_rating']);

            if ($response->successful()) {
                $body = $response->json();

                return [
                    'ok'                   => true,
                    'display_phone_number' => (string) ($body['display_phone_number'] ?? ''),
                    'verified_name'        => (string) ($body['verified_name'] ?? ''),
                    'error'                => null,
                ];
            }

            $errorBody    = $response->json();
            $errorMessage = $this->extractMetaError($errorBody, $response->status());

            Log::warning('WhatsApp credentials validation failed.', [
                'phone_number_id' => $phoneNumberId,
                'http_status'     => $response->status(),
                'meta_error'      => $errorMessage,
            ]);

            return $this->failure($errorMessage);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('WhatsApp credentials validation: connection timeout or refused.', [
                'phone_number_id' => $phoneNumberId,
                'error'           => $e->getMessage(),
            ]);

            return $this->failure('Não foi possível conectar à API da Meta. Tente novamente em instantes.');
        } catch (\Throwable $e) {
            Log::error('WhatsApp credentials validation: unexpected error.', [
                'phone_number_id' => $phoneNumberId,
                'error'           => $e->getMessage(),
                'class'           => $e::class,
            ]);

            return $this->failure('Erro inesperado ao verificar credenciais. Tente novamente.');
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function extractMetaError(array $body, int $httpStatus): string
    {
        $error   = $body['error'] ?? null;
        $message = is_array($error) ? (string) ($error['message'] ?? '') : '';
        $code    = is_array($error) ? ($error['code'] ?? null) : null;

        if ($message !== '') {
            return match ($code) {
                190    => 'Token inválido ou expirado.',
                100    => 'phone_number_id não encontrado ou sem permissão de acesso.',
                default => $message,
            };
        }

        return match ($httpStatus) {
            401     => 'Token inválido ou expirado.',
            403     => 'Sem permissão para acessar este número.',
            404     => 'phone_number_id não encontrado.',
            default => "Erro da API da Meta (HTTP {$httpStatus}).",
        };
    }

    /**
     * @return array{ok: bool, display_phone_number: null, verified_name: null, error: string}
     */
    private function failure(string $error): array
    {
        return [
            'ok'                   => false,
            'display_phone_number' => null,
            'verified_name'        => null,
            'error'                => $error,
        ];
    }
}
