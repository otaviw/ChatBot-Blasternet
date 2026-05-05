<?php

declare(strict_types=1);


namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiUsage;
use App\Models\AiUsageLog;
use App\Models\CompanyBotSetting;
use App\Models\User;
use App\Services\Ai\AiSafetyPipelineService;
use App\Services\Ai\Providers\AiProvider;
use App\Services\Ai\Tools\AiToolManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class InternalAiChatService
{
    use ResolvesAiProviderSettings;

    private const TOOL_RESULT_MAX_JSON_CHARS = 2000;

    private const TOOL_RESULT_META_MAX_CHARS = 600;

    public function __construct(
        private readonly AiProviderResolver $providerResolver,
        private readonly AiPromptService $promptService,
        private readonly AiConversationContextBuilder $contextBuilder,
        private readonly InternalAiConversationService $conversationService,
        private readonly AiUsageService $usageService,
        private readonly AiAuditService $aiAuditService,
        private readonly AiAccessService $aiAccessService,
        private readonly AiToolManager $toolManager,
        private readonly AiMetricsService $metricsService,
        private readonly AiSafetyPipelineService $safetyPipeline
    ) {}

    /**
     * @return array{
     *     conversation:AiConversation,
     *     user_message:AiMessage,
     *     assistant_message:AiMessage,
     *     provider:string,
     *     model:?string,
     *     tool_call_request:?array{tool:string,params:array<string,mixed>}
     * }
     */
    public function sendMessage(User $user, string $content, ?AiConversation $conversation = null, ?int $companyId = null): array
    {
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
        $promptResolution = $this->promptService->resolvePrompt(
            templateKey: 'internal_chat.system',
            legacyFallbackText: (string) ($this->resolveSystemPrompt() ?? ''),
            companyId: (int) $targetConversation->company_id,
            userId: (int) $user->id,
            conversationId: (int) $targetConversation->id,
            providerRequested: (string) ($settings?->ai_provider ?? ''),
            providerResolved: $providerName,
            metadata: [
                'feature' => AiUsageLog::FEATURE_INTERNAL_CHAT,
            ]
        );
        $systemPrompt = trim((string) ($promptResolution['content'] ?? ''));
        $systemPrompt = $systemPrompt !== '' ? $systemPrompt : null;
        $temperature = $this->resolveTemperature($settings);
        $maxResponseTokens = $this->resolveMaxResponseTokens($settings);
        $usageLog = $this->usageService->consumeInternalChat(
            $settings,
            $user,
            $targetConversation,
            $normalizedContent
        );

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

        $userMessage = AiMessage::query()->create([
            'ai_conversation_id' => (int) $targetConversation->id,
            'user_id' => (int) $user->id,
            'role' => AiMessage::ROLE_USER,
            'content' => $normalizedContent,
            'provider' => $providerName,
            'model' => $modelName,
            'meta' => [
                'source' => AiConversation::ORIGIN_INTERNAL_CHAT,
            ],
        ]);

        $this->touchLastMessageAt($targetConversation, $userMessage);

        $contextMessages = $this->contextBuilder->build($targetConversation, $systemPrompt, null, $settings, $normalizedContent);
        $contextMessages = $this->safetyPipeline->redactContextMessages($contextMessages);
        $provider = $this->providerResolver->resolve($providerName);
        $providerOptions = [
            'company_id' => (int) $targetConversation->company_id,
            'conversation_id' => (int) $targetConversation->id,
            'model' => $modelName,
            'temperature' => $temperature,
            'max_response_tokens' => $maxResponseTokens,
            'request_timeout_ms' => (int) config('ai.request_timeout_ms', 30000),
        ];

        $firstAttempt = $this->callProvider($provider, $contextMessages, $providerOptions);
        $providerResult = $firstAttempt['provider_result'];
        $this->usageService->updateTokensUsed($usageLog, $providerResult);
        $responseTimeMs = $firstAttempt['response_time_ms'];
        $tokensUsed = $this->usageService->tokensFromProviderResult($providerResult);

        $this->metricsService->updateFromProviderResult(
            $usageLog,
            $providerName,
            $modelName,
            AiUsageLog::FEATURE_INTERNAL_CHAT,
            $providerResult,
            $responseTimeMs,
            $tokensUsed
        );

        if (! (bool) ($providerResult['ok'] ?? false)) {
            $this->aiAuditService->logMessageSent(
                (int) $targetConversation->company_id,
                (int) $user->id,
                (int) $targetConversation->id,
                [
                    'status' => 'error',
                    'message_id' => (int) $userMessage->id,
                    'user_message' => $normalizedContent,
                    'assistant_response' => null,
                    'error' => $this->providerFailureMessage($providerResult),
                ]
            );
            $this->logProviderFailure($targetConversation, $providerName, $modelName, $providerResult);

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

        $toolCallRequest = $this->extractToolCallRequest($assistantText);
        $assistantMeta = [
            'source' => AiConversation::ORIGIN_INTERNAL_CHAT,
        ];
        if ($toolCallRequest !== null) {
            $assistantMeta['tool_call_request'] = $toolCallRequest;

            $toolFlow = $this->handleToolCallFlow(
                $provider,
                $providerOptions,
                $targetConversation,
                $systemPrompt,
                $settings,
                $user,
                $toolCallRequest
            );

            $assistantMeta = array_merge($assistantMeta, $toolFlow['meta']);

            $toolAssistantText = trim((string) ($toolFlow['assistant_text'] ?? ''));
            if ($toolAssistantText !== '') {
                $assistantText = $toolAssistantText;
            }

            $toolProviderResult = is_array($toolFlow['provider_result'] ?? null) ? $toolFlow['provider_result'] : null;
            if (is_array($toolProviderResult)) {
                $providerResult = $toolProviderResult;
                $this->usageService->updateTokensUsed($usageLog, $providerResult);
            }

            $responseTimeMs += (int) ($toolFlow['response_time_ms'] ?? 0);

            $finalTokens = $this->usageService->tokensFromProviderResult($providerResult);
            $this->metricsService->updateFromProviderResult(
                $usageLog,
                $providerName,
                $modelName,
                AiUsageLog::FEATURE_INTERNAL_CHAT,
                $providerResult,
                $responseTimeMs,
                $finalTokens
            );
        }

        $assistantMessage = AiMessage::query()->create([
            'ai_conversation_id' => (int) $targetConversation->id,
            'user_id' => null,
            'role' => AiMessage::ROLE_ASSISTANT,
            'content' => $assistantText,
            'provider' => $providerName,
            'model' => $modelName,
            'response_time_ms' => $responseTimeMs,
            'raw_payload' => is_array($providerResult) ? $providerResult : null,
            'meta' => $assistantMeta,
        ]);

        $this->touchLastMessageAt($targetConversation, $assistantMessage);

        $toolUsed = is_string($assistantMeta['tool_used'] ?? null)
            ? trim((string) $assistantMeta['tool_used'])
            : null;
        $toolUsed = $toolUsed !== '' ? $toolUsed : null;

        $this->usageService->logUsage(
            (int) $targetConversation->company_id,
            (int) $user->id,
            (int) $targetConversation->id,
            AiUsage::FEATURE_INTERNAL_CHAT,
            $toolUsed,
            $this->usageService->tokensFromProviderResult($providerResult)
        );
        $this->aiAuditService->logMessageSent(
            (int) $targetConversation->company_id,
            (int) $user->id,
            (int) $targetConversation->id,
            [
                'status' => 'ok',
                'message_id' => (int) $userMessage->id,
                'assistant_message_id' => (int) $assistantMessage->id,
                'user_message' => $normalizedContent,
                'assistant_response' => $assistantText,
                'tool_used' => $toolUsed,
            ]
        );

        return [
            'conversation' => $targetConversation->fresh(),
            'user_message' => $userMessage,
            'assistant_message' => $assistantMessage,
            'provider' => $providerName,
            'model' => $modelName,
            'tool_call_request' => $toolCallRequest,
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

        if ($companyProvider === '') {
            return $globalProvider;
        }

        if ($this->providerResolver->supports($companyProvider)) {
            return $companyProvider;
        }

        Log::warning('ai.internal_chat.company_provider_invalid', [
            'company_id' => (int) ($settings?->company_id ?? 0),
            'provider' => $companyProvider,
            'fallback' => $globalProvider,
        ]);

        return $globalProvider;
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

        return 'Não foi possível gerar resposta da IA para esta conversa.';
    }

    /**
     * @param  array<string, mixed>  $providerResult
     */
    private function logProviderFailure(AiConversation $conversation, string $providerName, ?string $modelName, array $providerResult): void
    {
        $providerMeta = is_array($providerResult['meta'] ?? null) ? $providerResult['meta'] : null;

        Log::warning('ai.internal_chat.provider_failed', [
            'conversation_id' => (int) $conversation->id,
            'company_id' => (int) $conversation->company_id,
            'provider' => $providerName,
            'model' => $modelName,
            'error' => $providerResult['error'] ?? null,
            'meta' => $providerMeta,
        ]);
    }

    private function touchLastMessageAt(AiConversation $conversation, AiMessage $message): void
    {
        $conversation->last_message_at = $message->created_at;
        $conversation->save();
    }

    /**
     * @return array{tool:string,params:array<string,mixed>}|null
     */
    private function extractToolCallRequest(string $assistantText): ?array
    {
        $payload = $this->decodeToolCallPayload($assistantText);
        if (! is_array($payload)) {
            return null;
        }

        $tool = trim((string) ($payload['tool'] ?? ''));
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];

        if ($tool === '') {
            return null;
        }

        return [
            'tool' => $tool,
            'params' => $params,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeToolCallPayload(string $assistantText): ?array
    {
        $raw = trim($assistantText);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/^\s*```(?:json)?\s*(\{[\s\S]*\})\s*```\s*$/i', $raw, $matches) !== 1) {
            return null;
        }

        $insideFence = trim((string) ($matches[1] ?? ''));
        if ($insideFence === '') {
            return null;
        }

        $decodedInsideFence = json_decode($insideFence, true);

        return is_array($decodedInsideFence) ? $decodedInsideFence : null;
    }

    /**
     * @param  array<string, mixed>  $providerOptions
     * @param  array{tool:string,params:array<string,mixed>}  $toolCallRequest
     * @return array{
     *     assistant_text:?string,
     *     provider_result:?array<string,mixed>,
     *     response_time_ms:int,
     *     meta:array<string,mixed>
     * }
     */
    private function handleToolCallFlow(
        AiProvider $provider,
        array $providerOptions,
        AiConversation $conversation,
        ?string $systemPrompt,
        ?CompanyBotSetting $settings,
        User $user,
        array $toolCallRequest
    ): array {
        $toolName = trim((string) ($toolCallRequest['tool'] ?? ''));
        $params = is_array($toolCallRequest['params'] ?? null) ? $toolCallRequest['params'] : [];

        $tool = $this->toolManager->findTool($toolName);
        if ($tool === null) {
            Log::warning('ai.internal_chat.tool_unknown', [
                'conversation_id' => (int) $conversation->id,
                'company_id' => (int) $conversation->company_id,
                'tool' => $toolName,
            ]);

            return $this->fallbackWithoutTool(
                $provider,
                $providerOptions,
                $conversation,
                $systemPrompt,
                $settings,
                $user,
                'unknown_tool',
                ['tool' => $toolName]
            );
        }

        $normalizedToolName = trim($tool->getName());
        if (! $this->hasValidBasicParams($normalizedToolName, $params)) {
            Log::warning('ai.internal_chat.tool_invalid_params', [
                'conversation_id' => (int) $conversation->id,
                'company_id' => (int) $conversation->company_id,
                'tool' => $normalizedToolName,
                'params' => $params,
            ]);

            return $this->fallbackWithoutTool(
                $provider,
                $providerOptions,
                $conversation,
                $systemPrompt,
                $settings,
                $user,
                'invalid_params',
                [
                    'tool' => $normalizedToolName,
                    'params' => $params,
                ]
            );
        }

        $toolParams = $params;
        $toolParams['company_id'] = (int) $conversation->company_id;

        try {
            $toolResult = $tool->execute($toolParams);
        } catch (Throwable $exception) {
            Log::warning('ai.internal_chat.tool_execution_failed', [
                'conversation_id' => (int) $conversation->id,
                'company_id' => (int) $conversation->company_id,
                'tool' => $normalizedToolName,
                'error' => $exception->getMessage(),
            ]);

            return $this->fallbackWithoutTool(
                $provider,
                $providerOptions,
                $conversation,
                $systemPrompt,
                $settings,
                $user,
                'tool_execution_failed',
                [
                    'tool' => $normalizedToolName,
                    'error' => $exception->getMessage(),
                ]
            );
        }

        $safeResultJson = $this->encodeToolResultForContext($toolResult);
        $contextMessages = $this->contextBuilder->build($conversation, $systemPrompt, null, $settings);
        $contextMessages[] = [
            'role' => AiMessage::ROLE_SYSTEM,
            'content' => "Resultado da ferramenta {$normalizedToolName}:\n{$safeResultJson}",
        ];

        $followUpAttempt = $this->callProvider($provider, $contextMessages, $providerOptions);
        $followUpResult = $followUpAttempt['provider_result'];
        if (! (bool) ($followUpResult['ok'] ?? false)) {
            Log::warning('ai.internal_chat.tool_followup_failed', [
                'conversation_id' => (int) $conversation->id,
                'company_id' => (int) $conversation->company_id,
                'tool' => $normalizedToolName,
                'error' => $followUpResult['error'] ?? null,
                'meta' => is_array($followUpResult['meta'] ?? null) ? $followUpResult['meta'] : null,
            ]);

            return $this->fallbackWithoutTool(
                $provider,
                $providerOptions,
                $conversation,
                $systemPrompt,
                $settings,
                $user,
                'tool_followup_failed',
                [
                    'tool' => $normalizedToolName,
                    'error' => $followUpResult['error'] ?? null,
                ]
            );
        }

        $followUpText = trim((string) ($followUpResult['text'] ?? ''));
        if ($followUpText === '') {
            return $this->fallbackWithoutTool(
                $provider,
                $providerOptions,
                $conversation,
                $systemPrompt,
                $settings,
                $user,
                'tool_followup_empty',
                ['tool' => $normalizedToolName]
            );
        }

        $toolResultSummary = $this->summarizeToolResultForMeta($toolResult);
        $this->aiAuditService->logToolExecuted(
            (int) $conversation->company_id,
            (int) $user->id,
            (int) $conversation->id,
            [
                'tool' => $normalizedToolName,
                'result' => $toolResultSummary,
            ]
        );

        return [
            'assistant_text' => $followUpText,
            'provider_result' => $followUpResult,
            'response_time_ms' => $followUpAttempt['response_time_ms'],
            'meta' => [
                'tool_call_execution_status' => 'executed',
                'tool_used' => $normalizedToolName,
                'tool_result' => $toolResultSummary,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $providerOptions
     * @return array{
     *     assistant_text:?string,
     *     provider_result:?array<string,mixed>,
     *     response_time_ms:int,
     *     meta:array<string,mixed>
     * }
     */
    private function fallbackWithoutTool(
        AiProvider $provider,
        array $providerOptions,
        AiConversation $conversation,
        ?string $systemPrompt,
        ?CompanyBotSetting $settings,
        User $user,
        string $reason,
        array $metadata = []
    ): array {
        $contextMessages = $this->contextBuilder->build($conversation, $systemPrompt, null, $settings);
        $contextMessages[] = [
            'role' => AiMessage::ROLE_SYSTEM,
            'content' => 'Não foi possível usar a ferramenta solicitada. Responda sem ferramenta com base no contexto disponível.',
        ];

        $attempt = $this->callProvider($provider, $contextMessages, $providerOptions);
        $result = $attempt['provider_result'];
        $text = trim((string) ($result['text'] ?? ''));

        if (! (bool) ($result['ok'] ?? false) || $text === '') {
            $this->aiAuditService->logToolFailed(
                (int) $conversation->company_id,
                (int) $user->id,
                (int) $conversation->id,
                [
                    'reason' => $reason,
                    'fallback' => 'failed',
                    'metadata' => $metadata,
                ]
            );

            return [
                'assistant_text' => null,
                'provider_result' => null,
                'response_time_ms' => 0,
                'meta' => [
                    'tool_call_execution_status' => $reason,
                ],
            ];
        }

        $this->aiAuditService->logToolFailed(
            (int) $conversation->company_id,
            (int) $user->id,
            (int) $conversation->id,
            [
                'reason' => $reason,
                'fallback' => 'responded_without_tool',
                'metadata' => $metadata,
            ]
        );

        return [
            'assistant_text' => $text,
            'provider_result' => $result,
            'response_time_ms' => $attempt['response_time_ms'],
            'meta' => [
                'tool_call_execution_status' => $reason,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $providerOptions
     * @return array{provider_result:array<string,mixed>,response_time_ms:int}
     */
    private function callProvider(AiProvider $provider, array $messages, array $providerOptions): array
    {
        $startedAt = microtime(true);

        try {
            $result = $provider->reply($messages, $providerOptions);
        } catch (Throwable $exception) {
            $responseTimeMs = (int) round((microtime(true) - $startedAt) * 1000);

            return [
                'provider_result' => [
                    'ok' => false,
                    'text' => null,
                    'error' => 'provider_exception',
                    'meta' => [
                        'message' => 'Falha ao obter resposta da IA.',
                        'exception_message' => $exception->getMessage(),
                    ],
                ],
                'response_time_ms' => $responseTimeMs,
            ];
        }

        $responseTimeMs = (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'provider_result' => is_array($result) ? $result : ['ok' => false, 'text' => null, 'error' => 'invalid_provider_result'],
            'response_time_ms' => $responseTimeMs,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function hasValidBasicParams(string $toolName, array $params): bool
    {
        if ($toolName === 'get_customer_by_phone') {
            $phone = trim((string) ($params['phone'] ?? ''));

            return $phone !== '';
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $toolResult
     */
    private function encodeToolResultForContext(array $toolResult): string
    {
        $json = json_encode($toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            return '{"error":"tool_result_encoding_failed"}';
        }

        if (mb_strlen($json) <= self::TOOL_RESULT_MAX_JSON_CHARS) {
            return $json;
        }

        return mb_substr($json, 0, self::TOOL_RESULT_MAX_JSON_CHARS).'...';
    }

    /**
     * @param  array<string, mixed>  $toolResult
     * @return array<string, mixed>
     */
    private function summarizeToolResultForMeta(array $toolResult): array
    {
        $summary = [];

        foreach (['found', 'name', 'plan'] as $field) {
            $value = $toolResult[$field] ?? null;
            if (is_scalar($value) || $value === null) {
                $summary[$field] = $value;
            }
        }

        if ($summary !== []) {
            return $summary;
        }

        $json = json_encode($toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            return ['preview' => 'tool_result_encoding_failed', 'truncated' => false];
        }

        $truncated = mb_strlen($json) > self::TOOL_RESULT_META_MAX_CHARS;

        return [
            'preview' => $truncated
                ? mb_substr($json, 0, self::TOOL_RESULT_META_MAX_CHARS).'...'
                : $json,
            'truncated' => $truncated,
        ];
    }
}
