<?php

declare(strict_types=1);


namespace App\Support;

final class AppointmentStatus
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const RESCHEDULED = 'rescheduled';
    public const CANCELLED = 'cancelled';
    public const COMPLETED = 'completed';
    public const NO_SHOW = 'no_show';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::CONFIRMED,
            self::RESCHEDULED,
            self::CANCELLED,
            self::COMPLETED,
            self::NO_SHOW,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function blocking(): array
    {
        return [
            self::PENDING,
            self::CONFIRMED,
        ];
    }
}

