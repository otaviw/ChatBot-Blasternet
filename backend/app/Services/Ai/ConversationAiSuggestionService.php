<?php

declare(strict_types=1);


namespace App\Services\Ai;

use App\Models\AiMessage;
use App\Models\AiUsageLog;
use App\Models\CompanyBotSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ai\AiSafetyPipelineService;
use App\Services\Ai\Rag\AiKnowledgeRetrieverService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ConversationAiSuggestionService
{
    use ResolvesAiProviderSettings;

    public function __construct(
        private readonly AiProviderResolver $providerResolver,
        private readonly AiPromptService $promptService,
        private readonly AiMetricsService $metricsService,
        private readonly AiSafetyPipelineService $safetyPipeline,
        private readonly AiAuditService $aiAuditService,
        private readonly AiKnowledgeRetrieverService $retrieverService
    ) {}

    /**
     * Generate a suggestion and return it with metadata.
     *
     * @return array{suggestion: string, confidence_score: float, used_rag: bool, rag_chunks: list<array{title:string,content:string,score:float|null}>}
     */
    public function generateSuggestion(Conversation $conversation, CompanyBotSetting $settings): array
    {
        $providerName = $this->resolveProviderName($settings);
        $modelName = $this->resolveModelName($settings);
        $temperature = $this->resolveTemperature($settings);
        $maxResponseTokens = $this->resolveMaxResponseTokens($settings);
        $historyLimit = $this->resolveHistoryLimit($settings);
        $promptResolution = $this->promptService->resolvePrompt(
            templateKey: 'conversation_suggestion.system',
            legacyFallbackText: (string) ($this->resolveSystemPrompt() ?? ''),
            companyId: (int) $conversation->company_id,
            userId: null,
            conversationId: null,
            providerRequested: (string) ($settings->ai_provider ?? ''),
            providerResolved: $providerName,
            metadata: [
                'feature' => AiUsageLog::FEATURE_CONVERSATION_SUGGESTION,
                'inbox_conversation_id' => (int) $conversation->id,
            ]
        );
        $baseSystemPrompt = trim((string) ($promptResolution['content'] ?? ''));

        // Extract last user message BEFORE building context so we can use it
        // as the RAG query to retrieve the most relevant knowledge chunks.
        $rawHistory = $this->fetchRawHistory($conversation, $historyLimit);
        $lastUserText = $this->extractLastUserTextFromHistory($rawHistory);

        [$contextMessages, $ragChunks] = $this->buildContextMessagesWithMeta(
            $conversation,
            $settings,
            $historyLimit,
            $lastUserText,
            $rawHistory,
            $baseSystemPrompt
        );
        if ($lastUserText !== null && $lastUserText !== '') {
            $safetyResult = $this->safetyPipeline->run($lastUserText);
            if ($safetyResult->blocked) {
                // conversation_id = null porque AiAuditLog.conversation_id referencia ai_conversations
                // o ID da conversa regular fica em metadata
                $this->aiAuditService->logSafetyBlocked(
                    companyId: (int) $conversation->company_id,
                    userId: null,
                    conversationId: null,
                    metadata: [
                        'feature' => AiUsageLog::FEATURE_CONVERSATION_SUGGESTION,
                        'stage' => $safetyResult->blockStage,
                        'reason' => $safetyResult->blockReason,
                        'flags' => $safetyResult->flags,
                        'inbox_conversation_id' => (int) $conversation->id,
                    ]
                );
                $this->metricsService->record(
                    companyId: (int) $conversation->company_id,
                    userId: null,
                    // ai_usage_logs.conversation_id references ai_conversations, not inbox conversations.
                    conversationId: null,
                    provider: $providerName,
                    model: $modelName,
                    feature: AiUsageLog::FEATURE_CONVERSATION_SUGGESTION,
                    status: AiUsageLog::STATUS_ERROR,
                    responseTimeMs: 0,
                    tokensUsed: null,
                    errorType: 'safety_blocked'
                );
                throw ValidationException::withMessages([
                    'ai' => ['Não foi possível gerar sugestão para esta conversa.'],
                ]);
            }
        }
        // Redacta PII dos turnos de usuário antes de enviar ao provider
        $contextMessages = $this->safetyPipeline->redactContextMessages($contextMessages);
        $usedRag = $ragChunks !== [];

        $provider = $this->providerResolver->resolve($providerName);
        $providerOptions = [
            'company_id' => (int) $conversation->company_id,
            'conversation_id' => (int) $conversation->id,
            'model' => $modelName,
            'temperature' => $temperature,
            'max_response_tokens' => $maxResponseTokens,
            'request_timeout_ms' => (int) config('ai.request_timeout_ms', 30000),
        ];

        $measured = $this->metricsService->measure(fn () => $provider->reply($contextMessages, $providerOptions));
        $responseTimeMs = $measured['response_time_ms'];
        $exception = $measured['exception'];

        if ($exception !== null) {
            $this->recordErrorMetric($conversation, $providerName, $modelName, $responseTimeMs, 'provider_exception', $exception);
            throw ValidationException::withMessages([
                'ai' => ['Falha ao obter sugestão da IA.'],
            ]);
        }

        $providerResult = is_array($measured['result']) ? $measured['result'] : ['ok' => false, 'error' => 'invalid_result'];
        $tokensUsed = $this->extractTokensUsed($providerResult);

        // Registra métricas da chamada
        $this->metricsService->record(
            companyId: (int) $conversation->company_id,
            userId: null,
            // ai_usage_logs.conversation_id references ai_conversations, not inbox conversations.
            conversationId: null,
            provider: $providerName,
            model: $modelName,
            feature: AiUsageLog::FEATURE_CONVERSATION_SUGGESTION,
            status: (bool) ($providerResult['ok'] ?? false) ? AiUsageLog::STATUS_OK : AiUsageLog::STATUS_ERROR,
            responseTimeMs: $responseTimeMs,
            tokensUsed: $tokensUsed,
            errorType: ! (bool) ($providerResult['ok'] ?? false)
                ? AiMetricsService::normalizeErrorType($providerResult['error'] ?? null)
                : null
        );

        if (! (bool) ($providerResult['ok'] ?? false)) {
            $this->logProviderFailure($conversation, $providerName, $modelName, $providerResult);

            throw ValidationException::withMessages([
                'ai' => [$this->providerFailureMessage($providerResult)],
            ]);
        }

        $suggestion = trim((string) ($providerResult['text'] ?? ''));
        if ($suggestion === '') {
            throw ValidationException::withMessages([
                'ai' => ['A IA não retornou sugestão para esta conversa.'],
            ]);
        }

        return [
            'suggestion'       => $suggestion,
            'confidence_score' => $this->calculateConfidenceScore($usedRag, $ragChunks),
            'used_rag'         => $usedRag,
            'rag_chunks'       => $ragChunks,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Message>|null  $preloadedHistory
     * @return array{0: list<array{role:string,content:string}>, 1: list<array{title:string,content:string,score:float|null}>}
     */
    private function buildContextMessagesWithMeta(
        Conversation $conversation,
        CompanyBotSetting $settings,
        int $historyLimit,
        ?string $ragQuery,
        ?\Illuminate\Support\Collection $preloadedHistory,
        string $baseSystemPrompt = ''
    ): array {
        $messages = [];

        [$systemPrompt, $ragChunks] = $this->buildSystemPromptWithMeta($conversation, $settings, $baseSystemPrompt, $ragQuery);
        if ($systemPrompt !== '') {
            $messages[] = [
                'role'    => AiMessage::ROLE_SYSTEM,
                'content' => $systemPrompt,
            ];
        }

        $history = $preloadedHistory ?? $this->fetchRawHistory($conversation, $historyLimit);

        foreach ($history as $item) {
            $role    = $this->normalizeRole((string) ($item->direction ?? ''));
            $content = $this->normalizeContent($item);

            if ($role === null || $content === '') {
                continue;
            }

            $messages[] = ['role' => $role, 'content' => $content];
        }

        return [$messages, $ragChunks];
    }

    /**
     * @return array{0: string, 1: list<array{title:string,content:string,score:float|null}>}
     */
    private function buildSystemPromptWithMeta(
        Conversation $conversation,
        CompanyBotSetting $settings,
        string $baseSystemPrompt = '',
        ?string $ragQuery = null
    ): array
    {
        $sections = [];

        $basePrompt = trim($baseSystemPrompt);
        if ($basePrompt !== '') {
            $sections[] = $basePrompt;
        }

        $companyPrompt = trim((string) ($settings->ai_system_prompt ?? ''));
        if ($companyPrompt !== '') {
            $sections[] = $companyPrompt;
        }

        $companyStyleLines = array_filter([
            ($p = trim((string) ($settings->ai_persona ?? '')))   !== '' ? "Persona: {$p}" : null,
            ($t = trim((string) ($settings->ai_tone ?? '')))      !== '' ? "Tom: {$t}"     : null,
            ($l = trim((string) ($settings->ai_language ?? '')))  !== '' ? "Idioma: {$l}"  : null,
            ($f = trim((string) ($settings->ai_formality ?? ''))) !== '' ? "Formalidade: {$f}" : null,
        ]);

        if ($companyStyleLines !== []) {
            $sections[] = implode(PHP_EOL, $companyStyleLines);
        }

        $topK   = (int) config('ai.rag.top_k', 3);
        $chunks = $this->retrieverService->retrieve((int) $conversation->company_id, $ragQuery, $topK);

        if ($chunks !== []) {
            $lines = ['Base de conhecimento da empresa:'];
            $n = 1;
            foreach ($chunks as $chunk) {
                $title   = trim((string) ($chunk['title']   ?? ''));
                $content = trim((string) ($chunk['content'] ?? ''));
                if ($content === '') {
                    continue;
                }
                $label    = $title !== '' ? $title : 'Sem titulo';
                $lines[]  = "{$n}. {$label}: {$content}";
                $n++;
            }
            if (count($lines) > 1) {
                $sections[] = implode(PHP_EOL, $lines);
            }
        }

        return [implode(PHP_EOL.PHP_EOL, $sections), $chunks];
    }

    /**
     * Heuristic confidence score based on whether RAG was used and best similarity score.
     *
     * @param  list<array{score:float|null}>  $ragChunks
     */
    private function calculateConfidenceScore(bool $usedRag, array $ragChunks): float
    {
        if (! $usedRag) {
            return 0.5;
        }

        $bestScore = 0.0;
        foreach ($ragChunks as $chunk) {
            $score = (float) ($chunk['score'] ?? 0.0);
            if ($score > $bestScore) {
                $bestScore = $score;
            }
        }

        if ($bestScore === 0.0) {
            // RAG used via static fallback (no similarity score)
            return 0.65;
        }

        // Map cosine similarity [0.3, 1.0] to [0.80, 0.97]
        return round(min(0.97, max(0.80, 0.80 + ($bestScore - 0.3) * (0.17 / 0.7))), 2);
    }

    private function recordErrorMetric(
        Conversation $conversation,
        string $providerName,
        ?string $modelName,
        int $responseTimeMs,
        string $errorKey,
        ?\Throwable $exception = null
    ): void {
        $this->metricsService->record(
            companyId: (int) $conversation->company_id,
            userId: null,
            // ai_usage_logs.conversation_id references ai_conversations, not inbox conversations.
            conversationId: null,
            provider: $providerName,
            model: $modelName,
            feature: AiUsageLog::FEATURE_CONVERSATION_SUGGESTION,
            status: AiUsageLog::STATUS_ERROR,
            responseTimeMs: $responseTimeMs,
            tokensUsed: null,
            errorType: AiMetricsService::normalizeErrorType($errorKey, $exception)
        );
    }

    /**
     * @param  array<string, mixed>  $providerResult
     */
    private function extractTokensUsed(array $providerResult): ?int
    {
        $meta = is_array($providerResult['meta'] ?? null) ? $providerResult['meta'] : [];

        $candidates = [
            $providerResult['tokens_used'] ?? null,
            $meta['tokens_used'] ?? null,
            data_get($meta, 'usage.total_tokens'),
            data_get($meta, 'usage.tokens'),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                $value = (int) $candidate;
                if ($value >= 0) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, string>>
     */
    /**
     * Fetch the raw message history without building context (used for early RAG query extraction).
     *
     * @return \Illuminate\Support\Collection<int, Message>
     */
    private function fetchRawHistory(Conversation $conversation, int $historyLimit): \Illuminate\Support\Collection
    {
        return Message::query()
            ->where('conversation_id', (int) $conversation->id)
            ->orderByDesc('id')
            ->limit($historyLimit)
            ->get(['id', 'direction', 'text', 'content_type'])
            ->reverse()
            ->values();
    }

    /**
     * Extract the last user (direction=in) message text from raw history.
     *
     * @param  \Illuminate\Support\Collection<int, Message>  $history
     */
    private function extractLastUserTextFromHistory(\Illuminate\Support\Collection $history): ?string
    {
        foreach ($history->reverse() as $item) {
            if (mb_strtolower(trim((string) ($item->direction ?? ''))) === 'in') {
                $content = $this->normalizeContent($item);
                if ($content !== '') {
                    return $content;
                }
            }
        }

        return null;
    }

    private function resolveHistoryLimit(CompanyBotSetting $settings): int
    {
        $configured = $settings->ai_max_context_messages;
        if ($configured !== null && is_numeric($configured)) {
            return min(100, max(1, (int) $configured));
        }

        return min(100, max(1, (int) config('ai.history_messages_limit', 10)));
    }

    private function resolveProviderName(CompanyBotSetting $settings): string
    {
        $globalProvider = $this->providerResolver->resolveProviderName($this->providerResolver->defaultProviderName());
        $companyProvider = mb_strtolower(trim((string) ($settings->ai_provider ?? '')));

        if ($companyProvider === '') {
            return $globalProvider;
        }

        if ($this->providerResolver->supports($companyProvider)) {
            return $companyProvider;
        }

        Log::warning('ai.suggestion.company_provider_invalid', [
            'company_id' => (int) ($settings->company_id ?? 0),
            'provider' => $companyProvider,
            'fallback' => $globalProvider,
        ]);

        return $globalProvider;
    }

    private function normalizeRole(string $direction): ?string
    {
        $normalized = mb_strtolower(trim($direction));

        return match ($normalized) {
            'in' => AiMessage::ROLE_USER,
            'out' => AiMessage::ROLE_ASSISTANT,
            default => null,
        };
    }

    private function normalizeContent(Message $message): string
    {
        $text = trim((string) ($message->text ?? ''));
        if ($text !== '') {
            return $text;
        }

        $contentType = mb_strtolower(trim((string) ($message->content_type ?? 'text')));
        if ($contentType === 'image') {
            return (string) $message->direction === 'in'
                ? '[Cliente enviou uma imagem.]'
                : '[Atendente enviou uma imagem.]';
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $providerResult
     */
    private function providerFailureMessage(array $providerResult): string
    {
        $meta = is_array($providerResult['meta'] ?? null) ? $providerResult['meta'] : [];
        $configuredMessage = trim((string) ($meta['message'] ?? ''));

        if ($configuredMessage !== '') {
            return $configuredMessage;
        }

        return 'Não foi possível gerar sugestão de IA para esta conversa.';
    }

    /**
     * @param  array<string, mixed>  $providerResult
     */
    private function logProviderFailure(
        Conversation $conversation,
        string $providerName,
        ?string $modelName,
        array $providerResult
    ): void {
        $providerMeta = is_array($providerResult['meta'] ?? null) ? $providerResult['meta'] : null;

        Log::warning('ai.suggestion.provider_failed', [
            'conversation_id' => (int) $conversation->id,
            'company_id' => (int) $conversation->company_id,
            'provider' => $providerName,
            'model' => $modelName,
            'error' => $providerResult['error'] ?? null,
            'meta' => $providerMeta,
        ]);
    }
}
