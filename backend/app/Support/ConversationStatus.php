<?php

namespace App\Support;

final class ConversationStatus
{
    public const OPEN = 'open';
    public const IN_PROGRESS = 'in_progress';
    public const CLOSED = 'closed';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::OPEN,
            self::IN_PROGRESS,
            self::CLOSED,
        ];
    }
}
