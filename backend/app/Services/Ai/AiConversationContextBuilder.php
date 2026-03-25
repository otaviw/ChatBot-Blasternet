<?php

namespace App\Services\Ai;

use App\Models\AiCompanyKnowledge;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\CompanyBotSetting;

class AiConversationContextBuilder
{
    /**
     * @return array<int, array<string, string>>
     */
    public function build(
        AiConversation $conversation,
        ?string $systemPrompt = null,
        ?int $historyLimit = null,
        ?CompanyBotSetting $companySettings = null
    ): array {
        $messages = [];

        $prompt = $this->buildSystemPrompt($systemPrompt, $companySettings);
        if ($prompt !== '') {
            $messages[] = [
                'role' => AiMessage::ROLE_SYSTEM,
                'content' => $prompt,
            ];
        }

        $knowledgePrompt = $this->buildKnowledgePrompt((int) $conversation->company_id);
        if ($knowledgePrompt !== '') {
            $messages[] = [
                'role' => AiMessage::ROLE_SYSTEM,
                'content' => $knowledgePrompt,
            ];
        }

        $limit = $this->resolveHistoryLimit($historyLimit, $companySettings);

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

    private function resolveHistoryLimit(?int $historyLimit, ?CompanyBotSetting $companySettings): int
    {
        $configured = $historyLimit;

        if ($configured === null && $companySettings?->ai_max_context_messages !== null) {
            $configured = (int) $companySettings->ai_max_context_messages;
        }

        if ($configured === null) {
            $configured = (int) config('ai.history_messages_limit', 20);
        }

        return min(100, max(1, (int) $configured));
    }

    private function buildSystemPrompt(?string $systemPrompt, ?CompanyBotSetting $companySettings): string
    {
        $sections = [];

        $basePrompt = trim((string) $systemPrompt);
        if ($basePrompt !== '') {
            $sections[] = $basePrompt;
        }

        $companyPrompt = trim((string) ($companySettings?->ai_system_prompt ?? ''));
        if ($companyPrompt !== '') {
            $sections[] = $companyPrompt;
        }

        $persona = trim((string) ($companySettings?->ai_persona ?? ''));
        $tone = trim((string) ($companySettings?->ai_tone ?? ''));
        $language = trim((string) ($companySettings?->ai_language ?? ''));
        $formality = trim((string) ($companySettings?->ai_formality ?? ''));

        $companyStyleLines = [];
        if ($persona !== '') {
            $companyStyleLines[] = "Persona: {$persona}";
        }
        if ($tone !== '') {
            $companyStyleLines[] = "Tom: {$tone}";
        }
        if ($language !== '') {
            $companyStyleLines[] = "Idioma: {$language}";
        }
        if ($formality !== '') {
            $companyStyleLines[] = "Formalidade: {$formality}";
        }

        if ($companyStyleLines !== []) {
            $sections[] = implode(PHP_EOL, $companyStyleLines);
        }

        return implode(PHP_EOL.PHP_EOL, $sections);
    }

    private function buildKnowledgePrompt(int $companyId): string
    {
        if ($companyId <= 0) {
            return '';
        }

        $knowledgeItems = AiCompanyKnowledge::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(3)
            ->get(['title', 'content']);

        if ($knowledgeItems->isEmpty()) {
            return '';
        }

        $lines = ['Base de conhecimento da empresa:'];

        foreach ($knowledgeItems as $index => $item) {
            $title = trim((string) $item->title);
            $content = trim((string) $item->content);

            if ($content === '') {
                continue;
            }

            $label = $title !== '' ? $title : 'Sem titulo';
            $number = $index + 1;
            $lines[] = "{$number}. {$label}: {$content}";
        }

        if (count($lines) === 1) {
            return '';
        }

        return implode(PHP_EOL, $lines);
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

