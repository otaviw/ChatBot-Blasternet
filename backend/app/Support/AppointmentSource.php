<?php

declare(strict_types=1);


namespace App\Support;

final class AppointmentSource
{
    public const WHATSAPP = 'whatsapp';
    public const DASHBOARD = 'dashboard';
    public const SYSTEM = 'system';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::WHATSAPP,
            self::DASHBOARD,
            self::SYSTEM,
        ];
    }
}

