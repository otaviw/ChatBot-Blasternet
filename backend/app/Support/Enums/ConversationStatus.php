<?php

namespace App\Support\Enums;

enum ConversationStatus: string
{
    case OPEN        = 'open';
    case IN_PROGRESS = 'in_progress';
    case CLOSED      = 'closed';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
