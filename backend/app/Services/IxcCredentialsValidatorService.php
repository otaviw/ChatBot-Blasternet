<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\IxcUrlGuard;
use Illuminate\Http\Client\ConnectionException;
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

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withOptions([
                    'verify' => ! $selfSigned,
                ])
                ->withHeaders([
                    'ixcsoft' => $normalizedToken,
                    'Authorization' => 'Basic ' . base64_encode($normalizedToken),
                ])
                ->get($url, $params);
        } catch (ConnectionException) {
            return [
                'ok' => false,
                'error' => 'Nao foi possivel conectar ao servidor IXC.',
                'details' => null,
            ];
        } catch (\Throwable) {
            return [
                'ok' => false,
                'error' => 'Erro inesperado ao validar a integracao IXC.',
                'details' => null,
            ];
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return [
                'ok' => false,
                'error' => 'Token IXC invalido ou sem permissao.',
                'details' => ['status' => $response->status()],
            ];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error' => "Servidor IXC respondeu com HTTP {$response->status()}.",
                'details' => ['status' => $response->status()],
            ];
        }

        return [
            'ok' => true,
            'error' => null,
            'details' => ['status' => $response->status()],
        ];
    }
}
