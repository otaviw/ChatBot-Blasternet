<?php

namespace App\Support\Enums;

enum UserRole: string
{
    case SYSTEM_ADMIN  = 'system_admin';
    case COMPANY_ADMIN = 'company_admin';
    case AGENT         = 'agent';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Canonical value that should be stored in the DB. */
    public static function normalize(string $raw): string
    {
        $value = mb_strtolower(trim($raw));

        return match ($value) {
            'admin'   => self::SYSTEM_ADMIN->value,
            'company' => self::COMPANY_ADMIN->value,
            default   => $value,
        };
    }
}
