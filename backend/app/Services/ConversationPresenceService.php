<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class ConversationPresenceService
{
    public function touch(int $userId, int $conversationId): void
    {
        if ($userId <= 0 || $conversationId <= 0) {
            return;
        }

        Cache::put(
            $this->key($userId, $conversationId),
            true,
            now()->addSeconds($this->ttlSeconds())
        );
    }

    public function clear(int $userId, int $conversationId): void
    {
        if ($userId <= 0 || $conversationId <= 0) {
            return;
        }

        Cache::forget($this->key($userId, $conversationId));
    }

    public function isConversationOpenByUser(int $userId, int $conversationId): bool
    {
        if ($userId <= 0 || $conversationId <= 0) {
            return false;
        }

        return Cache::has($this->key($userId, $conversationId));
    }

    private function key(int $userId, int $conversationId): string
    {
        return "realtime:presence:user:{$userId}:conversation:{$conversationId}";
    }

    private function ttlSeconds(): int
    {
        return max(10, (int) config('realtime.presence.ttl_seconds', 40));
    }
}
