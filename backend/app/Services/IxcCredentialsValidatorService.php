<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\IxcUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class IxcCredentialsValidatorService
{
    /**
     * @return array{ok: bool, error: string|null, details: array<string,mixed>|null}
     */
    public function validate(string $baseUrl, string $token, bool $selfSigned, int $timeoutSeconds): array
    {
        $normalizedBaseUrl = rtrim(trim($baseUrl), '/');
        $normalizedToken = trim($token);
        $timeout = max(5, min(60, $timeoutSeconds));

        if ($normalizedBaseUrl === '' || $normalizedToken === '') {
            return [
                'ok' => false,
                'error' => 'URL e token da IXC sao obrigatorios.',
                'details' => null,
            ];
        }

        if (! IxcUrlGuard::isSafeBaseUrl($normalizedBaseUrl, (bool) config('ixc.allow_private_hosts', false))) {
            return [
                'ok' => false,
                'error' => 'URL da IXC invalida ou nao permitida.',
                'details' => null,
            ];
        }

        $url = $normalizedBaseUrl . '/fn_areceber';
        $params = [
            'qtype' => 'fn_areceber.id',
            'query' => '-1',
            'oper' => '=',
            'rp' => 1,
            'sortname' => 'fn_areceber.id',
            'sortorder' => 'desc',
        ];

        $lastExceptionMessage = null;
        $response = null;
        $modes = ['listar', 'token'];
        foreach ($modes as $mode) {
            try {
                $response = Http::timeout($timeout)
                    ->acceptJson()
                    ->withOptions([
                        'verify' => ! $selfSigned,
                    ])
                    ->withHeaders([
                        'ixcsoft' => $mode === 'listar' ? 'listar' : $normalizedToken,
                        'Authorization' => 'Basic ' . base64_encode($normalizedToken),
                    ])
                    ->send('GET', $url, [
                        'json' => $params,
                    ]);
            } catch (ConnectionException) {
                $lastExceptionMessage = 'Nao foi possivel conectar ao servidor IXC.';
                continue;
            } catch (\Throwable) {
                $lastExceptionMessage = 'Erro inesperado ao validar a integracao IXC.';
                continue;
            }

            if (! $response->successful()) {
                if ($response->status() === 401 || $response->status() === 403) {
                    continue;
                }

                return [
                    'ok' => false,
                    'error' => "Servidor IXC respondeu com HTTP {$response->status()}.",
                    'details' => ['status' => $response->status(), 'mode' => $mode],
                ];
            }

            if ($this->isProviderError($response)) {
                $payload = $response->json();
                $error = trim((string) (($payload['message'] ?? $payload['mensagem'] ?? $payload['error'] ?? '')));
                if ($error !== '') {
                    return [
                        'ok' => false,
                        'error' => $error,
                        'details' => ['status' => $response->status(), 'mode' => $mode],
                    ];
                }
            }

            return [
                'ok' => true,
                'error' => null,
                'details' => ['status' => $response->status(), 'mode' => $mode],
            ];
        }

        if ($response instanceof Response && ($response->status() === 401 || $response->status() === 403)) {
            return [
                'ok' => false,
                'error' => 'Token IXC invalido ou sem permissao.',
                'details' => ['status' => $response->status()],
            ];
        }

        if ($lastExceptionMessage !== null) {
            return [
                'ok' => false,
                'error' => $lastExceptionMessage,
                'details' => null,
            ];
        }

        return [
            'ok' => false,
            'error' => 'Falha ao validar credenciais IXC.',
            'details' => null,
        ];
    }

    private function isProviderError(Response $response): bool
    {
        $payload = $response->json();
        if (! is_array($payload)) {
            return false;
        }

        $type = strtolower(trim((string) ($payload['type'] ?? '')));
        if ($type === 'error') {
            return true;
        }

        return array_key_exists('error', $payload) && ! empty($payload['error']);
    }
}
