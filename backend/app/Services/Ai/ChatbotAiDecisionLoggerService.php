<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiChatbotDecisionLog;

class ChatbotAiDecisionLoggerService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function logDecision(array $payload): AiChatbotDecisionLog
    {
        return AiChatbotDecisionLog::query()->create([
            'company_id' => (int) ($payload['company_id'] ?? 0),
            'conversation_id' => isset($payload['conversation_id']) ? (int) $payload['conversation_id'] : null,
            'message_id' => isset($payload['message_id']) ? (int) $payload['message_id'] : null,
            'user_id' => isset($payload['user_id']) ? (int) $payload['user_id'] : null,
            'mode' => (string) ($payload['mode'] ?? AiChatbotDecisionLog::MODE_OFF),
            'gate_result' => is_array($payload['gate_result'] ?? null) ? $payload['gate_result'] : null,
            'intent' => isset($payload['intent']) ? (string) $payload['intent'] : null,
            'confidence' => isset($payload['confidence']) ? (float) $payload['confidence'] : null,
            'action' => isset($payload['action']) ? (string) $payload['action'] : null,
            'handoff_reason' => isset($payload['handoff_reason']) ? (string) $payload['handoff_reason'] : null,
            'used_knowledge' => (bool) ($payload['used_knowledge'] ?? false),
            'knowledge_refs' => is_array($payload['knowledge_refs'] ?? null) ? $payload['knowledge_refs'] : null,
            'latency_ms' => isset($payload['latency_ms']) ? (int) $payload['latency_ms'] : null,
            'tokens_used' => isset($payload['tokens_used']) ? (int) $payload['tokens_used'] : null,
            'provider' => isset($payload['provider']) ? (string) $payload['provider'] : null,
            'model' => isset($payload['model']) ? (string) $payload['model'] : null,
            'error' => isset($payload['error']) ? (string) $payload['error'] : null,
        ]);
    }
}
