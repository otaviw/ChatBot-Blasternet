<?php

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiUsage;
use App\Models\AiUsageLog;
use App\Models\CompanyBotSetting;
use App\Models\User;
use App\Services\Ai\Providers\AiStreamingProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Handles the streaming path for internal AI chat.
 * Tool calls are intentionally not supported here — the non-streaming
 * sendMessage() in InternalAiChatService handles that flow.
 */
class InternalAiChatStreamService
{
    public function __construct(
        private readonly AiProviderResolver $providerResolver,
        private readonly AiConversationContextBuilder $contextBuilder,
        private readonly InternalAiConversationService $conversationService,
        private readonly AiUsageService $usageService,
        private readonly AiAuditService $aiAuditService,
        private readonly AiAccessService $aiAccessService,
        private readonly AiMetricsService $metricsService,
        private readonly AiSafetyPipelineService $safetyPipeline
    ) {}

    /**
     * Stream the AI response, calling $onChunk for every text delta.
     * Persists user and assistant messages, updates metrics, and writes
     * the audit log — all after the stream completes.
     *
     * @param  callable(string):void  $onChunk
     * @return array{
     *     conversation:AiConversation,
     *     user_message:AiMessage,
     *     assistant_message:AiMessage,
     *     provider:string,
     *     model:?string
     * }
     *
     * @throws ValidationException
     */
    public function streamMessage(
        User $user,
        string $content,
        ?AiConversation $conversation,
        callable $onChunk,
        ?int $companyId = null
    ): array {
        $normalizedContent = trim($content);
        if ($normalizedContent === '') {
            throw ValidationException::withMessages([
                'content' => ['Informe uma mensagem para continuar.'],
            ]);
        }

        $settings = $this->conversationService->requireInternalChatSettings($user, $companyId);
        $this->aiAccessService->assertCanUseInternalAi($user, $settings);

        $targetConversation = $this->resolveConversation($conversation, $user, $companyId);
        $providerName = $this->resolveProviderName($settings);
        $modelName = $this->resolveModelName($settings);
        $systemPrompt = $this->resolveSystemPrompt();
        $temperature = $this->resolveTemperature($settings);
        $maxResponseTokens = $this->resolveMaxResponseTokens($settings);

        $usageLog = $this->usageService->consumeInternalChat(
            $settings,
            $user,
            $targetConversation,
            $normalizedContent
        );

        // ── Safety pipeline ───────────────────────────────────────────────────
        $safetyResult = $this->safetyPipeline->run($normalizedContent);
        if ($safetyResult->blocked) {
            $this->aiAuditService->logSafetyBlocked(
                (int) $targetConversation->company_id,
                (int) $user->id,
                (int) $targetConversation->id,
                [
                    'feature' => AiUsageLog::FEATURE_INTERNAL_CHAT,
                    'stage' => $safetyResult->blockStage,
                    'reason' => $safetyResult->blockReason,
                    'flags' => $safetyResult->flags,
                ]
            );
            throw ValidationException::withMessages([
                'ai' => ['Sua mensagem não pôde ser processada. Reformule e tente novamente.'],
            ]);
        }
        $normalizedContent = $safetyResult->sanitizedInput;

        // ── Persist user message ──────────────────────────────────────────────
        $userMessage = AiMessage::query()->create([
            'ai_conversation_id' => (int) $targetConversation->id,
            'user_id' => (int) $user->id,
            'role' => AiMessage::ROLE_USER,
            'content' => $normalizedContent,
            'provider' => $providerName,
            'model' => $modelName,
            'meta' => ['source' => AiConversation::ORIGIN_INTERNAL_CHAT],
        ]);
        $this->touchLastMessageAt($targetConversation, $userMessage);

        // ── Build context (no tool list in streaming mode) ────────────────────
        $contextMessages = $this->contextBuilder->build(
            $targetConversation,
            $systemPrompt,
            null,            // no tools injected
            $settings,
            $normalizedContent  // RAG query
        );
        $contextMessages = $this->safetyPipeline->redactContextMessages($contextMessages);

        $providerOptions = [
            'company_id' => (int) $targetConversation->company_id,
            'conversation_id' => (int) $targetConversation->id,
            'model' => $modelName,
            'temperature' => $temperature,
            'max_response_tokens' => $maxResponseTokens,
            'request_timeout_ms' => (int) config('ai.request_timeout_ms', 30000),
        ];

        // ── Stream or fallback to non-streaming ───────────────────────────────
        $provider = $this->providerResolver->resolve($providerName);
        $startedAt = microtime(true);

        if ($provider instanceof AiStreamingProvider) {
            try {
                $providerResult = $provider->streamReply($contextMessages, $providerOptions, $onChunk);
            } catch (Throwable $exception) {
                $responseTimeMs = (int) round((microtime(true) - $startedAt) * 1000);
                $providerResult = [
                    'ok' => false,
                    'text' => null,
                    'error' => 'provider_exception',
                    'meta' => [
                        'message' => 'Falha ao obter resposta da IA.',
                        'exception_message' => $exception->getMessage(),
                    ],
                ];
                $this->updateMetricsOnError($usageLog, $providerName, $modelName, $providerResult, $responseTimeMs);
                throw ValidationException::withMessages([
                    'ai' => ['Não foi possível gerar resposta da IA. Tente novamente.'],
                ]);
            }
        } else {
            // Non-streaming fallback: call reply() and emit full text as single delta
            try {
                $providerResult = $provider->reply($contextMessages, $providerOptions);
                if ((bool) ($providerResult['ok'] ?? false) && is_string($providerResult['text'])) {
                    $onChunk($providerResult['text']);
                }
            } catch (Throwable $exception) {
                $responseTimeMs = (int) round((microtime(true) - $startedAt) * 1000);
                $providerResult = [
                    'ok' => false,
                    'text' => null,
                    'error' => 'provider_exception',
                    'meta' => ['message' => 'Falha ao obter resposta da IA.'],
                ];
                $this->updateMetricsOnError($usageLog, $providerName, $modelName, $providerResult, $responseTimeMs);
                throw ValidationException::withMessages([
                    'ai' => ['Não foi possível gerar resposta da IA. Tente novamente.'],
                ]);
            }
        }

        $responseTimeMs = (int) round((microtime(true) - $startedAt) * 1000);

        // ── Handle provider error ─────────────────────────────────────────────
        if (! (bool) ($providerResult['ok'] ?? false)) {
            $this->updateMetricsOnError($usageLog, $providerName, $modelName, $providerResult, $responseTimeMs);
            $this->aiAuditService->logMessageSent(
                (int) $targetConversation->company_id,
                (int) $user->id,
                (int) $targetConversation->id,
                [
                    'status' => 'error',
                    'message_id' => (int) $userMessage->id,
                    'error' => $this->providerFailureMessage($providerResult),
                ]
            );

            Log::warning('ai.internal_chat.stream.provider_failed', [
                'conversation_id' => (int) $targetConversation->id,
                'company_id' => (int) $targetConversation->company_id,
                'provider' => $providerName,
                'error' => $providerResult['error'] ?? null,
            ]);

            throw ValidationException::withMessages([
                'ai' => [$this->providerFailureMessage($providerResult)],
            ]);
        }

        $assistantText = trim((string) ($providerResult['text'] ?? ''));
        if ($assistantText === '') {
            throw ValidationException::withMessages([
                'ai' => ['A IA não retornou conteúdo para esta conversa.'],
            ]);
        }

        // ── Update metrics ────────────────────────────────────────────────────
        $tokensUsed = $this->usageService->tokensFromProviderResult($providerResult);
        $this->usageService->updateTokensUsed($usageLog, $providerResult);
        $this->metricsService->updateFromProviderResult(
            $usageLog,
            $providerName,
            $modelName,
            AiUsageLog::FEATURE_INTERNAL_CHAT,
            $providerResult,
            $responseTimeMs,
            $tokensUsed
        );

        // ── Persist assistant message ─────────────────────────────────────────
        $assistantMessage = AiMessage::query()->create([
            'ai_conversation_id' => (int) $targetConversation->id,
            'user_id' => null,
            'role' => AiMessage::ROLE_ASSISTANT,
            'content' => $assistantText,
            'provider' => $providerName,
            'model' => $modelName,
            'response_time_ms' => $responseTimeMs,
            'raw_payload' => is_array($providerResult) ? $providerResult : null,
            'meta' => ['source' => AiConversation::ORIGIN_INTERNAL_CHAT],
        ]);
        $this->touchLastMessageAt($targetConversation, $assistantMessage);

        // ── Usage + audit ─────────────────────────────────────────────────────
        $this->usageService->logUsage(
            (int) $targetConversation->company_id,
            (int) $user->id,
            (int) $targetConversation->id,
            AiUsage::FEATURE_INTERNAL_CHAT,
            null,
            $tokensUsed
        );
        $this->aiAuditService->logMessageSent(
            (int) $targetConversation->company_id,
            (int) $user->id,
            (int) $targetConversation->id,
            [
                'status' => 'ok',
                'message_id' => (int) $userMessage->id,
                'assistant_message_id' => (int) $assistantMessage->id,
            ]
        );

        return [
            'conversation' => $targetConversation->fresh(),
            'user_message' => $userMessage,
            'assistant_message' => $assistantMessage,
            'provider' => $providerName,
            'model' => $modelName,
        ];
    }

    private function resolveConversation(?AiConversation $conversation, User $user, ?int $companyId = null): AiConversation
    {
        if ($conversation instanceof AiConversation) {
            $this->conversationService->assertOwnedInternalConversation($conversation, $user);

            return $conversation;
        }

        return $this->conversationService->createForUser($user, null, $companyId);
    }

    private function resolveProviderName(?CompanyBotSetting $settings): string
    {
        $globalProvider = $this->providerResolver->resolveProviderName($this->providerResolver->defaultProviderName());
        $companyProvider = mb_strtolower(trim((string) ($settings?->ai_provider ?? '')));

        if ($companyProvider === '' || ! $this->providerResolver->supports($companyProvider)) {
            return $globalProvider;
        }

        return $companyProvider;
    }

    private function resolveModelName(?CompanyBotSetting $settings): ?string
    {
        $companyModel = trim((string) ($settings?->ai_model ?? ''));
        if ($companyModel !== '') {
            return $companyModel;
        }

        $globalModel = trim((string) config('ai.model', ''));

        return $globalModel !== '' ? $globalModel : null;
    }

    private function resolveSystemPrompt(): ?string
    {
        $globalPrompt = trim((string) config('ai.system_prompt', ''));

        return $globalPrompt !== '' ? $globalPrompt : null;
    }

    private function resolveTemperature(?CompanyBotSetting $settings): ?float
    {
        if ($settings?->ai_temperature !== null && is_numeric($settings->ai_temperature)) {
            return (float) $settings->ai_temperature;
        }

        $global = config('ai.temperature');
        if ($global !== null && is_numeric($global)) {
            return (float) $global;
        }

        return null;
    }

    private function resolveMaxResponseTokens(?CompanyBotSetting $settings): ?int
    {
        if ($settings?->ai_max_response_tokens !== null && is_numeric($settings->ai_max_response_tokens)) {
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

    /**
     * @param  array<string, mixed>  $providerResult
     */
    private function providerFailureMessage(array $providerResult): string
    {
        $meta = is_array($providerResult['meta'] ?? null) ? $providerResult['meta'] : [];
        $message = trim((string) ($meta['message'] ?? ''));

        return $message !== '' ? $message : 'Não foi possível gerar resposta da IA para esta conversa.';
    }

    /**
     * @param  array<string, mixed>  $providerResult
     */
    private function updateMetricsOnError(
        AiUsageLog $usageLog,
        string $providerName,
        ?string $modelName,
        array $providerResult,
        int $responseTimeMs
    ): void {
        $this->metricsService->updateFromProviderResult(
            $usageLog,
            $providerName,
            $modelName,
            AiUsageLog::FEATURE_INTERNAL_CHAT,
            $providerResult,
            $responseTimeMs,
            0
        );
    }

    private function touchLastMessageAt(AiConversation $conversation, AiMessage $message): void
    {
        $conversation->last_message_at = $message->created_at;
        $conversation->save();
    }
}
