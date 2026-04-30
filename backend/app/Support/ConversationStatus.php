<?php

declare(strict_types=1);


namespace App\Support;

use App\Support\Enums\ConversationStatus as ConversationStatusEnum;

final class ConversationStatus
{
    public const OPEN        = ConversationStatusEnum::OPEN->value;
    public const IN_PROGRESS = ConversationStatusEnum::IN_PROGRESS->value;
    public const CLOSED      = ConversationStatusEnum::CLOSED->value;

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return ConversationStatusEnum::values();
    }
}
