<?php

declare(strict_types=1);

namespace App\Services\Ai;

class ChatbotAiSuggestionResultNormalizer
{
    public static function toReplyText(mixed $result): ?string
    {
        if (is_string($result)) {
            $text = trim($result);

            return $text !== '' ? $text : null;
        }

        if (! is_array($result)) {
            return null;
        }

        $candidate = $result['suggestion'] ?? $result['text'] ?? null;
        if (! is_string($candidate)) {
            return null;
        }

        $text = trim($candidate);

        return $text !== '' ? $text : null;
    }
}
