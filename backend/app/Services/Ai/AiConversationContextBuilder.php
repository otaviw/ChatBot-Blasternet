<?php

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\CompanyBotSetting;
use App\Services\Ai\Rag\AiKnowledgeRetrieverService;
use App\Services\Ai\Tools\AiToolManager;

class AiConversationContextBuilder
{
    public function __construct(
        private readonly AiCompanyKnowledgeService $knowledgeService,
        private readonly AiToolManager $toolManager,
        private readonly AiKnowledgeRetrieverService $retrieverService
    ) {}

    /**
     * @param  string|null  $currentQuery  The user's current message — used for RAG retrieval.
     *                                     When null, falls back to static (recency-ordered) knowledge.
     * @return array<int, array<string, string>>
     */
    public function build(
        AiConversation $conversation,
        ?string $systemPrompt = null,
        ?int $historyLimit = null,
        ?CompanyBotSetting $companySettings = null,
        ?string $currentQuery = null
    ): array {
        $messages = [];
        $limit = $this->resolveHistoryLimit($historyLimit, $companySettings);

        $history = AiMessage::query()
            ->where('ai_conversation_id', (int) $conversation->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'role', 'content', 'created_at'])
            ->reverse()
            ->values();

        $this->appendSystemMessage($messages, $this->buildSystemPrompt($systemPrompt, $companySettings));
        $this->appendSystemMessage($messages, $this->buildKnowledgePrompt((int) $conversation->company_id, $currentQuery));
        $this->appendSystemMessage($messages, $this->buildHistoryPrompt($history));

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

        $this->appendSystemMessage($messages, $this->buildToolsPrompt());

        return $messages;
    }

    /**
     * Adiciona uma mensagem de sistema ao array de contexto apenas se o conteúdo não for vazio.
     * Centraliza o padrão repetido em build() para cada seção do prompt.
     *
     * @param  array<int, array<string, string>>  $messages
     */
    private function appendSystemMessage(array &$messages, string $content): void
    {
        if ($content === '') {
            return;
        }

        $messages[] = [
            'role'    => AiMessage::ROLE_SYSTEM,
            'content' => $content,
        ];
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

        return min(20, max(10, (int) $configured));
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

    private function buildKnowledgePrompt(int $companyId, ?string $query = null): string
    {
        $ragEnabled = (bool) config('ai.rag.enabled', false);
        // When RAG is disabled preserve original static behaviour (top-5 by recency).
        // When RAG is enabled use the configured top_k (default 3, fewer and more targeted).
        $topK = $ragEnabled ? (int) config('ai.rag.top_k', 3) : 5;

        $chunks = $this->retrieverService->retrieve($companyId, $query, $topK);

        if ($chunks === []) {
            return '';
        }

        $lines = ['Informacoes da empresa:'];

        foreach ($chunks as $chunk) {
            $title = trim((string) ($chunk['title'] ?? ''));
            $content = trim((string) ($chunk['content'] ?? ''));

            if ($content === '') {
                continue;
            }

            $label = $title !== '' ? $title : 'Sem titulo';
            $lines[] = "- {$label}: {$content}";
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

    /**
     * @param  \Illuminate\Support\Collection<int, AiMessage>  $history
     */
    private function buildHistoryPrompt(\Illuminate\Support\Collection $history): string
    {
        if ($history->isEmpty()) {
            return '';
        }

        $lines = ['Histórico recente:'];

        foreach ($history as $item) {
            $role = $this->normalizeRole((string) ($item->role ?? ''));
            $content = trim((string) ($item->content ?? ''));

            if ($role === null || $content === '') {
                continue;
            }

            $label = match ($role) {
                AiMessage::ROLE_USER => 'User',
                AiMessage::ROLE_ASSISTANT => 'Assistant',
                default => 'System',
            };

            $lines[] = "{$label}: {$content}";
        }

        if (count($lines) === 1) {
            return '';
        }

        return implode(PHP_EOL, $lines);
    }

    private function buildToolsPrompt(): string
    {
        $tools = $this->toolManager->getAvailableTools();
        if ($tools === []) {
            return '';
        }

        $lines = ['Ferramentas disponiveis:'];

        foreach ($tools as $tool) {
            $name = trim($tool->getName());
            $description = trim($tool->getDescription());

            if ($name === '') {
                continue;
            }

            $lines[] = "- {$name}: {$description}";
        }

        if (count($lines) === 1) {
            return '';
        }

        return implode(PHP_EOL, $lines);
    }
}
