<?php

namespace App\Support;

final class ConversationAssignedType
{
    public const USER = 'user';
    public const AREA = 'area';
    public const BOT = 'bot';
    public const UNASSIGNED = 'unassigned';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::USER,
            self::AREA,
            self::BOT,
            self::UNASSIGNED,
        ];
    }
}
