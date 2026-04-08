<?php

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
    public function __construct(
        private readonly AiProviderResolver $providerResolver,
        private readonly AiMetricsService $metricsService,
        private readonly AiSafetyPipelineService $safetyPipeline,
        private readonly AiAuditService $aiAuditService,
        private readonly AiKnowledgeRetrieverService $retrieverService
    ) {}

    public function generateSuggestion(Conversation $conversation, CompanyBotSetting $settings): string
    {
        $providerName = $this->resolveProviderName($settings);
        $modelName = $this->resolveModelName($settings);
        $temperature = $this->resolveTemperature($settings);
        $maxResponseTokens = $this->resolveMaxResponseTokens($settings);
        $historyLimit = $this->resolveHistoryLimit($settings);

        // Extract last user message BEFORE building context so we can use it
        // as the RAG query to retrieve the most relevant knowledge chunks.
        $rawHistory = $this->fetchRawHistory($conversation, $historyLimit);
        $lastUserText = $this->extractLastUserTextFromHistory($rawHistory);

        $contextMessages = $this->buildContextMessages($conversation, $settings, $historyLimit, $lastUserText, $rawHistory);
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
                    conversationId: (int) $conversation->id,
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
                'ai' => ['Falha ao obter sugestao da IA.'],
            ]);
        }

        $providerResult = is_array($measured['result']) ? $measured['result'] : ['ok' => false, 'error' => 'invalid_result'];
        $tokensUsed = $this->extractTokensUsed($providerResult);

        // Registra métricas da chamada
        $this->metricsService->record(
            companyId: (int) $conversation->company_id,
            userId: null,
            conversationId: (int) $conversation->id,
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
                'ai' => ['A IA nao retornou sugestao para esta conversa.'],
            ]);
        }

        return $suggestion;
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
            conversationId: (int) $conversation->id,
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

    /**
     * @param  \Illuminate\Support\Collection<int, Message>|null  $preloadedHistory
     * @return list<array{role: string, content: string}>
     */
    private function buildContextMessages(
        Conversation $conversation,
        CompanyBotSetting $settings,
        int $historyLimit,
        ?string $ragQuery = null,
        ?\Illuminate\Support\Collection $preloadedHistory = null
    ): array {
        $messages = [];

        $systemPrompt = $this->buildSystemPrompt($conversation, $settings, $ragQuery);
        if ($systemPrompt !== '') {
            $messages[] = [
                'role' => AiMessage::ROLE_SYSTEM,
                'content' => $systemPrompt,
            ];
        }

        $history = $preloadedHistory ?? $this->fetchRawHistory($conversation, $historyLimit);

        foreach ($history as $item) {
            $role = $this->normalizeRole((string) ($item->direction ?? ''));
            $content = $this->normalizeContent($item);

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

    private function buildSystemPrompt(Conversation $conversation, CompanyBotSetting $settings, ?string $ragQuery = null): string
    {
        $sections = [];

        $globalPrompt = trim((string) config('ai.system_prompt', ''));
        if ($globalPrompt !== '') {
            $sections[] = $globalPrompt;
        }

        $companyPrompt = trim((string) ($settings->ai_system_prompt ?? ''));
        if ($companyPrompt !== '') {
            $sections[] = $companyPrompt;
        }

        $persona = trim((string) ($settings->ai_persona ?? ''));
        $tone = trim((string) ($settings->ai_tone ?? ''));
        $language = trim((string) ($settings->ai_language ?? ''));
        $formality = trim((string) ($settings->ai_formality ?? ''));

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

        $knowledgePrompt = $this->buildKnowledgePrompt((int) $conversation->company_id, $ragQuery);
        if ($knowledgePrompt !== '') {
            $sections[] = $knowledgePrompt;
        }

        return implode(PHP_EOL.PHP_EOL, $sections);
    }

    private function buildKnowledgePrompt(int $companyId, ?string $query = null): string
    {
        if ($companyId <= 0) {
            return '';
        }

        $topK = (int) config('ai.rag.top_k', 3);
        $chunks = $this->retrieverService->retrieve($companyId, $query, $topK);

        if ($chunks === []) {
            return '';
        }

        $lines = ['Base de conhecimento da empresa:'];
        $number = 1;

        foreach ($chunks as $chunk) {
            $title = trim((string) ($chunk['title'] ?? ''));
            $content = trim((string) ($chunk['content'] ?? ''));

            if ($content === '') {
                continue;
            }

            $label = $title !== '' ? $title : 'Sem titulo';
            $lines[] = "{$number}. {$label}: {$content}";
            $number++;
        }

        if (count($lines) === 1) {
            return '';
        }

        return implode(PHP_EOL, $lines);
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

    private function resolveModelName(CompanyBotSetting $settings): ?string
    {
        $companyModel = trim((string) ($settings->ai_model ?? ''));
        if ($companyModel !== '') {
            return $companyModel;
        }

        $globalModel = trim((string) config('ai.model', ''));

        return $globalModel !== '' ? $globalModel : null;
    }

    private function resolveTemperature(CompanyBotSetting $settings): ?float
    {
        if ($settings->ai_temperature !== null && is_numeric($settings->ai_temperature)) {
            return (float) $settings->ai_temperature;
        }

        $global = config('ai.temperature');
        if ($global !== null && is_numeric($global)) {
            return (float) $global;
        }

        return null;
    }

    private function resolveMaxResponseTokens(CompanyBotSetting $settings): ?int
    {
        if ($settings->ai_max_response_tokens !== null && is_numeric($settings->ai_max_response_tokens)) {
            $value = (int) $settings->ai_max_response_tokens;

            return $value > 0 ? $value : null;
        }

        $global = config('ai.max_response_tokens');
        if ($global !== null && is_numeric($global)) {
            $value = (int) $global;

            return $value > 0 ? $value : null;
        }

        return null;
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

        return 'Nao foi possivel gerar sugestao de IA para esta conversa.';
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

