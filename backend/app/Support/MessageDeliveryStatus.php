<?php

namespace App\Support;

final class MessageDeliveryStatus
{
    public const PENDING = 'pending';
    public const SENT = 'sent';
    public const DELIVERED = 'delivered';
    public const READ = 'read';
    public const FAILED = 'failed';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::PENDING,
            self::SENT,
            self::DELIVERED,
            self::READ,
            self::FAILED,
        ];
    }
}
