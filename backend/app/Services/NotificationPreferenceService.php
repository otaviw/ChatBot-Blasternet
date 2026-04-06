<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotificationPreference;

class NotificationPreferenceService
{
    /** All notification types the system can dispatch */
    public const ALL_TYPES = [
        'customer_message',
        'conversation_transferred',
        'conversation_closed',
        'support_ticket_created',
        'support_ticket_message',
        'support_ticket_closed',
        'internal_chat_message',
        'chat_participant_added',
    ];

    /**
     * Returns the full preferences map for a user.
     * Missing types default to true (enabled).
     *
     * @return array<string, bool>
     */
    public function getForUser(User|int $user): array
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        $record = UserNotificationPreference::query()
            ->where('user_id', $userId)
            ->first();

        $stored = is_array($record?->preferences) ? $record->preferences : [];

        $result = [];
        foreach (self::ALL_TYPES as $type) {
            $result[$type] = array_key_exists($type, $stored) ? (bool) $stored[$type] : true;
        }

        return $result;
    }

    /**
     * Saves preferences for a user. Only keys in ALL_TYPES are accepted.
     *
     * @param  array<string, bool>  $preferences
     */
    public function saveForUser(User|int $user, array $preferences): void
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        $sanitized = [];
        foreach (self::ALL_TYPES as $type) {
            if (array_key_exists($type, $preferences)) {
                $sanitized[$type] = (bool) $preferences[$type];
            }
        }

        UserNotificationPreference::updateOrCreate(
            ['user_id' => $userId],
            ['preferences' => $sanitized]
        );
    }

    /**
     * Returns whether a specific notification type is enabled for a user.
     * Defaults to true (enabled) if no preference is stored.
     */
    public function isEnabled(User|int $user, string $type): bool
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;

        $record = UserNotificationPreference::query()
            ->where('user_id', $userId)
            ->first();

        if (! $record || ! is_array($record->preferences)) {
            return true;
        }

        $prefs = $record->preferences;

        return array_key_exists($type, $prefs) ? (bool) $prefs[$type] : true;
    }
}
