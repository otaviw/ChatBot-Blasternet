<?php

declare(strict_types=1);

namespace App\Support;

class IxcUrlGuard
{
    public static function isSafeBaseUrl(string $url, bool $allowPrivate = false): bool
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        return $allowPrivate || ! self::isPrivateOrLocalHost($host);
    }

    public static function isSafeInvoiceUrl(string $url, string $baseUrlHost, bool $allowPrivate = false): bool
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        if ($baseUrlHost !== '' && $host !== strtolower($baseUrlHost)) {
            return false;
        }

        return $allowPrivate || ! self::isPrivateOrLocalHost($host);
    }

    private static function isPrivateOrLocalHost(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return ! filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            if ($host === '::1') {
                return true;
            }

            return str_starts_with($host, 'fc')
                || str_starts_with($host, 'fd')
                || str_starts_with($host, 'fe80');
        }

        return false;
    }
}
