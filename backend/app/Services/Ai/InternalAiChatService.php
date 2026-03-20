<?php

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\CompanyBotSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class InternalAiChatService
{
    public function __construct(
        private readonly AiProviderResolver $providerResolver,
        private readonly AiConversationContextBuilder $contextBuilder,
        private readonly InternalAiConversationService $conversationService
    ) {}

    /**
     * @return array{
     *     conversation:AiConversation,
     *     user_message:AiMessage,
     *     assistant_message:AiMessage,
     *     provider:string,
     *     model:?string
     * }
     */
    public function sendMessage(User $user, string $content, ?AiConversation $conversation = null): array
    {
        $normalizedContent = trim($content);
        if ($normalizedContent === '') {
            throw ValidationException::withMessages([
                'content' => ['Informe uma mensagem para continuar.'],
            ]);
        }

        $settings = $this->conversationService->requireInternalChatSettings($user);

        $targetConversation = $this->resolveConversation($conversation, $user);
        $providerName = $this->resolveProviderName($settings);
        $modelName = $this->resolveModelName($settings);
        $systemPrompt = $this->resolveSystemPrompt($settings);
        $temperature = $this->resolveTemperature($settings);
        $maxResponseTokens = $this->resolveMaxResponseTokens($settings);

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

        $contextMessages = $this->contextBuilder->build($targetConversation, $systemPrompt);
        $provider = $this->providerResolver->resolve($providerName);

        $startedAt = microtime(true);
        $providerResult = $provider->reply($contextMessages, [
            'company_id' => (int) $targetConversation->company_id,
            'conversation_id' => (int) $targetConversation->id,
            'model' => $modelName,
            'temperature' => $temperature,
            'max_response_tokens' => $maxResponseTokens,
            'request_timeout_ms' => (int) config('ai.request_timeout_ms', 30000),
        ]);
        $responseTimeMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (! (bool) ($providerResult['ok'] ?? false)) {
            $this->logProviderFailure($targetConversation, $providerName, $providerResult);

            throw ValidationException::withMessages([
                'ai' => [$this->providerFailureMessage($providerResult)],
            ]);
        }

        $assistantText = trim((string) ($providerResult['text'] ?? ''));
        if ($assistantText === '') {
            throw ValidationException::withMessages([
                'ai' => ['A IA nao retornou conteudo para esta conversa.'],
            ]);
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
            'meta' => [
                'source' => AiConversation::ORIGIN_INTERNAL_CHAT,
            ],
        ]);

        $this->touchLastMessageAt($targetConversation, $assistantMessage);

        return [
            'conversation' => $targetConversation->fresh(),
            'user_message' => $userMessage,
            'assistant_message' => $assistantMessage,
            'provider' => $providerName,
            'model' => $modelName,
        ];
    }

    private function resolveConversation(?AiConversation $conversation, User $user): AiConversation
    {
        if ($conversation instanceof AiConversation) {
            $this->conversationService->assertOwnedInternalConversation($conversation, $user);

            return $conversation;
        }

        return $this->conversationService->createForUser($user);
    }

    private function resolveProviderName(?CompanyBotSetting $settings): string
    {
        $globalProvider = mb_strtolower(trim((string) config('ai.provider', 'null')));
        $companyProvider = mb_strtolower(trim((string) ($settings?->ai_provider ?? '')));

        if ($companyProvider === '') {
            return $globalProvider !== '' ? $globalProvider : 'null';
        }

        if ($this->providerResolver->supports($companyProvider)) {
            return $companyProvider;
        }

        if ($globalProvider !== '' && $this->providerResolver->supports($globalProvider)) {
            return $globalProvider;
        }

        return 'null';
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

    private function resolveSystemPrompt(?CompanyBotSetting $settings): ?string
    {
        $companyPrompt = trim((string) ($settings?->ai_system_prompt ?? ''));
        if ($companyPrompt !== '') {
            return $companyPrompt;
        }

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
        $configuredMessage = trim((string) ($meta['message'] ?? ''));

        if ($configuredMessage !== '') {
            return $configuredMessage;
        }

        return 'Nao foi possivel gerar resposta da IA para esta conversa.';
    }

    /**
     * @param  array<string, mixed>  $providerResult
     */
    private function logProviderFailure(AiConversation $conversation, string $providerName, array $providerResult): void
    {
        Log::warning('ai.internal_chat.provider_failed', [
            'conversation_id' => (int) $conversation->id,
            'company_id' => (int) $conversation->company_id,
            'provider' => $providerName,
            'error' => $providerResult['error'] ?? null,
        ]);
    }

    private function touchLastMessageAt(AiConversation $conversation, AiMessage $message): void
    {
        $conversation->last_message_at = $message->created_at;
        $conversation->save();
    }
}
