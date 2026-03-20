<?php

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;

class AiConversationContextBuilder
{
    /**
     * @return array<int, array<string, string>>
     */
    public function build(AiConversation $conversation, ?string $systemPrompt = null, ?int $historyLimit = null): array
    {
        $messages = [];

        $prompt = trim((string) $systemPrompt);
        if ($prompt !== '') {
            $messages[] = [
                'role' => AiMessage::ROLE_SYSTEM,
                'content' => $prompt,
            ];
        }

        $limit = $this->resolveHistoryLimit($historyLimit);

        $history = AiMessage::query()
            ->where('ai_conversation_id', (int) $conversation->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'role', 'content'])
            ->reverse()
            ->values();

        foreach ($history as $item) {
            $role = $this->normalizeRole((string) ($item->role ?? ''));
            $content = trim((string) ($item->content ?? ''));

            if ($role === null || $content === '') {
                continue;
            }

            $messages[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return $messages;
    }

    private function resolveHistoryLimit(?int $historyLimit): int
    {
        $configured = $historyLimit ?? (int) config('ai.history_messages_limit', 20);

        return min(100, max(1, (int) $configured));
    }

    private function normalizeRole(string $role): ?string
    {
        $normalized = mb_strtolower(trim($role));

        return match ($normalized) {
            AiMessage::ROLE_USER => AiMessage::ROLE_USER,
            AiMessage::ROLE_ASSISTANT => AiMessage::ROLE_ASSISTANT,
            AiMessage::ROLE_SYSTEM => AiMessage::ROLE_SYSTEM,
            default => null,
        };
    }
}
