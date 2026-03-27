<?php

namespace App\Services\Ai;

use App\Models\AiCompanyKnowledge;
use App\Models\AiMessage;
use App\Models\CompanyBotSetting;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ConversationAiSuggestionService
{
    public function __construct(
        private readonly AiProviderResolver $providerResolver
    ) {}

    public function generateSuggestion(Conversation $conversation, CompanyBotSetting $settings): string
    {
        $providerName = $this->resolveProviderName($settings);
        $modelName = $this->resolveModelName($settings);
        $temperature = $this->resolveTemperature($settings);
        $maxResponseTokens = $this->resolveMaxResponseTokens($settings);
        $historyLimit = $this->resolveHistoryLimit($settings);
        $contextMessages = $this->buildContextMessages($conversation, $settings, $historyLimit);

        $provider = $this->providerResolver->resolve($providerName);
        $providerResult = $provider->reply($contextMessages, [
            'company_id' => (int) $conversation->company_id,
            'conversation_id' => (int) $conversation->id,
            'model' => $modelName,
            'temperature' => $temperature,
            'max_response_tokens' => $maxResponseTokens,
            'request_timeout_ms' => (int) config('ai.request_timeout_ms', 30000),
        ]);

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

    /**
     * @return array<int, array<string, string>>
     */
    private function buildContextMessages(
        Conversation $conversation,
        CompanyBotSetting $settings,
        int $historyLimit
    ): array {
        $messages = [];

        $systemPrompt = $this->buildSystemPrompt($conversation, $settings);
        if ($systemPrompt !== '') {
            $messages[] = [
                'role' => AiMessage::ROLE_SYSTEM,
                'content' => $systemPrompt,
            ];
        }

        $history = Message::query()
            ->where('conversation_id', (int) $conversation->id)
            ->orderByDesc('id')
            ->limit($historyLimit)
            ->get(['id', 'direction', 'text', 'content_type'])
            ->reverse()
            ->values();

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

    private function buildSystemPrompt(Conversation $conversation, CompanyBotSetting $settings): string
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

        $knowledgePrompt = $this->buildKnowledgePrompt((int) $conversation->company_id);
        if ($knowledgePrompt !== '') {
            $sections[] = $knowledgePrompt;
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
            $title = trim((string) ($item->title ?? ''));
            $content = trim((string) ($item->content ?? ''));

            if ($content === '') {
                continue;
            }

            $number = $index + 1;
            $label = $title !== '' ? $title : 'Sem titulo';
            $lines[] = "{$number}. {$label}: {$content}";
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

