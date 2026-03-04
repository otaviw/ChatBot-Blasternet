<?php

namespace App\Support;

use App\Models\Conversation;
use Illuminate\Support\Collection;

class AdminPrivacySanitizer
{
    /**
     * @return array<string, mixed>
     */
    public static function conversationSummary(Conversation $conversation): array
    {
        $tags = is_array($conversation->tags) ? $conversation->tags : [];

        return [
            'id' => (int) $conversation->id,
            'company_id' => (int) $conversation->company_id,
            'company_name' => $conversation->company?->name,
            'customer_name' => $conversation->customer_name,
            'customer_phone_masked' => self::maskPhone((string) $conversation->customer_phone),
            'status' => $conversation->status,
            'handling_mode' => $conversation->handling_mode,
            'assigned_type' => $conversation->assigned_type,
            'messages_count' => (int) ($conversation->messages_count ?? 0),
            'tags_count' => count($tags),
            'created_at' => $conversation->created_at,
            'updated_at' => $conversation->updated_at,
            'assumed_at' => $conversation->assumed_at,
            'closed_at' => $conversation->closed_at,
        ];
    }

    /**
     * @param  Collection<int, Conversation>  $conversations
     * @return array<int, array<string, mixed>>
     */
    public static function conversationSummaryCollection(Collection $conversations): array
    {
        return $conversations
            ->map(fn(Conversation $conversation) => self::conversationSummary($conversation))
            ->values()
            ->all();
    }

    public static function maskPhone(?string $phone): ?string
    {
        $value = trim((string) $phone);
        if ($value === '') {
            return null;
        }

        $length = mb_strlen($value);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4).mb_substr($value, -4);
    }
}
