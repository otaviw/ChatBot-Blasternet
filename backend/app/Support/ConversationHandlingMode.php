<?php

declare(strict_types=1);


namespace App\Support;

final class ConversationHandlingMode
{
    public const BOT = 'bot';
    public const HUMAN = 'human';

    /**
     * Legacy value kept for backward compatibility while old rows are normalized.
     */
    public const LEGACY_MANUAL = 'manual';

    public static function normalize(?string $value): string
    {
        $mode = mb_strtolower(trim((string) $value));

        if ($mode === self::BOT) {
            return self::BOT;
        }

        if ($mode === self::LEGACY_MANUAL) {
            return self::HUMAN;
        }

        return self::HUMAN;
    }

    public static function isHuman(?string $value): bool
    {
        return self::normalize($value) === self::HUMAN;
    }

    public static function isBot(?string $value): bool
    {
        return self::normalize($value) === self::BOT;
    }
}
