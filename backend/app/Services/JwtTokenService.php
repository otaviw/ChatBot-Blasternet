<?php

declare(strict_types=1);


namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;

class JwtTokenService
{
    /**
     * @param  array<string, mixed>  $claims
     * @return array{token:string, expires_at:int}
     */
    public function createToken(array $claims, int $ttlSeconds): array
    {
        $secret = $this->secret();
        $now = now()->timestamp;
        $ttl = max(5, $ttlSeconds);
        $exp = $now + $ttl;

        $payload = array_merge([
            'iss' => (string) config('realtime.jwt.issuer'),
            'aud' => (string) config('realtime.jwt.audience'),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $exp,
            'jti' => (string) Str::uuid(),
        ], $claims);

        $headerJson = json_encode([
            'alg' => 'HS256',
            'typ' => 'JWT',
        ], JSON_UNESCAPED_SLASHES);

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($headerJson === false || $payloadJson === false) {
            throw new RuntimeException('Falha ao serializar token JWT.');
        }

        $segments = [
            $this->base64UrlEncode($headerJson),
            $this->base64UrlEncode($payloadJson),
        ];

        $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
        $segments[] = $this->base64UrlEncode($signature);

        return [
            'token' => implode('.', $segments),
            'expires_at' => $exp,
        ];
    }

    private function secret(): string
    {
        $secret = trim((string) config('realtime.jwt.secret', ''));
        if ($secret === '') {
            throw new RuntimeException('REALTIME_JWT_SECRET não configurado.');
        }

        return $secret;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
