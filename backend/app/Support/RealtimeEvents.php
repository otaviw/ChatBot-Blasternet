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
    public const APPOINTMENT_CREATED = 'appointment.created';
    public const APPOINTMENT_UPDATED = 'appointment.updated';
    public const CONVERSATION_TAGS_UPDATED = 'conversation.tags.updated';
    public const CUSTOMER_TYPING = 'customer.typing';
    public const CONVERSATION_COUNTERS_UPDATED = 'conversation.counters.updated';
    public const CAMPAIGN_UPDATED = 'campaign.updated';

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
            self::APPOINTMENT_CREATED,
            self::APPOINTMENT_UPDATED,
            self::CONVERSATION_TAGS_UPDATED,
            self::CUSTOMER_TYPING,
            self::CONVERSATION_COUNTERS_UPDATED,
            self::CAMPAIGN_UPDATED,
        ];
    }
}
