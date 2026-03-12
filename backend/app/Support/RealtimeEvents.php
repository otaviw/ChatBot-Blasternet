<?php

namespace App\Support;

final class RealtimeEvents
{
    public const MESSAGE_CREATED = 'message.created';
    public const MESSAGE_UPDATED = 'message.updated';
    public const MESSAGE_STATUS_UPDATED = 'message.status.updated';
    public const MESSAGE_REACTIONS_UPDATED = 'message.reactions.updated';
    public const BOT_UPDATED = 'bot.updated';
    public const CONVERSATION_TRANSFERRED = 'conversation.transferred';
    public const NOTIFICATION_CREATED = 'notification.created';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::MESSAGE_CREATED,
            self::MESSAGE_UPDATED,
            self::MESSAGE_STATUS_UPDATED,
            self::MESSAGE_REACTIONS_UPDATED,
            self::BOT_UPDATED,
            self::CONVERSATION_TRANSFERRED,
            self::NOTIFICATION_CREATED,
        ];
    }
}
