<?php

declare(strict_types=1);


namespace App\Support\Enums;

enum UserRole: string
{
    case SYSTEM_ADMIN  = 'system_admin';
    case RESELLER_ADMIN = 'reseller_admin';
    case COMPANY_ADMIN = 'company_admin';
    case AGENT         = 'agent';

    /** @var array<string, string> */
    private const LEGACY_ALIASES = [
        'admin' => self::SYSTEM_ADMIN->value,
        'company' => self::COMPANY_ADMIN->value,
    ];

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Canonical value that should be stored in the DB. */
    public static function normalize(string $raw): string
    {
        $normalized = mb_strtolower(trim($raw));

        return self::LEGACY_ALIASES[$normalized] ?? $normalized;
    }
}
