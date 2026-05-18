<?php

declare(strict_types=1);

namespace App\Support;

final class LogSanitizer
{
    public static function maskPhone(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return self::maskGeneric($raw, 2, 2);
        }

        if (strlen($digits) <= 4) {
            return str_repeat('*', strlen($digits));
        }

        return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }

    public static function maskToken(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        return self::maskGeneric($raw, 4, 4);
    }

    public static function maskAuthorization(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (stripos($raw, 'Bearer ') === 0) {
            $token = trim(substr($raw, 7));
            return 'Bearer ' . (self::maskToken($token) ?? '***');
        }

        return self::maskToken($raw);
    }

    public static function truncateText(?string $value, int $max = 80): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if ($max < 1) {
            return null;
        }

        if (mb_strlen($raw) <= $max) {
            return $raw;
        }

        return mb_substr($raw, 0, $max) . '...';
    }

    private static function maskGeneric(string $value, int $head, int $tail): string
    {
        $len = strlen($value);
        if ($len <= ($head + $tail)) {
            return str_repeat('*', $len);
        }

        return substr($value, 0, $head) . str_repeat('*', $len - $head - $tail) . substr($value, -$tail);
    }
}

