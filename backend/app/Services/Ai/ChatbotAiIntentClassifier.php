<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\CompanyBotSetting;
use App\Models\Conversation;
use Throwable;

class ChatbotAiIntentClassifier
{
    use ResolvesAiProviderSettings;

    /**
     * @var array<int, string>
     */
    private const ALLOWED_INTENTS = [
        'agendamento',
        'remarcar_agendamento',
        'cancelar_agendamento',
        'falar_com_atendente',
        'duvida_geral',
        'suporte_tecnico',
        'financeiro',
        'menu',
        'fallback',
    ];

    public function __construct(
        private readonly AiProviderResolver $providerResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *   intent:string,
     *   confidence:float,
     *   extracted_data:array<string, mixed>,
     *   suggested_reply:?string,
     *   should_transfer_to_human:bool,
     *   reason:string
     * }
     */
    public function classify(
        Conversation $conversation,
        CompanyBotSetting $settings,
        string $messageText,
        array $context = []
    ): array {
        $text = trim($messageText);
        if ($text === '') {
            return $this->safeFallback('empty_input');
        }

        if ($this->shouldBypassAi($text, $context)) {
            return [
                'intent' => 'menu',
                'confidence' => 1.0,
                'extracted_data' => ['raw_input' => $text],
                'suggested_reply' => null,
                'should_transfer_to_human' => false,
                'reason' => 'menu_option_detected_without_ai',
            ];
        }

        $providerName = $this->resolveProviderName($settings);
        $provider = $this->providerResolver->resolve($providerName);
        $modelName = $this->resolveModelName($settings);
        $temperature = $this->resolveTemperature($settings);
        $maxResponseTokens = $this->resolveMaxResponseTokens($settings);

        $messages = [
            [
                'role' => 'system',
                'content' => $this->systemInstruction(),
            ],
            [
                'role' => 'user',
                'content' => $text,
            ],
        ];

        $options = [
            'company_id' => (int) $conversation->company_id,
            'conversation_id' => (int) $conversation->id,
            'model' => $modelName,
            'temperature' => $temperature,
            'max_response_tokens' => $maxResponseTokens,
            'request_timeout_ms' => (int) config('ai.chatbot_request_timeout_ms', config('ai.request_timeout_ms', 30000)),
        ];

        try {
            $result = $provider->reply($messages, $options);
        } catch (Throwable) {
            return $this->safeFallback('provider_exception');
        }

        if (! (bool) ($result['ok'] ?? false)) {
            return $this->safeFallback('provider_failed');
        }

        $raw = trim((string) ($result['text'] ?? ''));
        if ($raw === '') {
            return $this->safeFallback('empty_provider_response');
        }

        $decoded = $this->decodeClassifierJson($raw);
        if (! is_array($decoded)) {
            return $this->safeFallback('invalid_classifier_output');
        }

        return $this->normalizeOutput($decoded, 'provider_classification');
    }

    private function systemInstruction(): string
    {
        return 'Classifique a intenção do cliente e retorne SOMENTE JSON válido com as chaves: intent, confidence, extracted_data, suggested_reply, should_transfer_to_human, reason. ' .
            'Intents permitidas: agendamento, remarcar_agendamento, cancelar_agendamento, falar_com_atendente, duvida_geral, suporte_tecnico, financeiro, menu, fallback. ' .
            'confidence deve ser número entre 0 e 1. Não execute ações.';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function shouldBypassAi(string $text, array $context): bool
    {
        if ((bool) ($context['interactive_reply'] ?? false)) {
            return true;
        }

        $messageMeta = is_array($context['message_meta'] ?? null) ? $context['message_meta'] : [];
        if ((bool) ($messageMeta['interactive_reply'] ?? false)) {
            return true;
        }

        $compact = preg_replace('/\s+/', '', mb_strtolower($text)) ?? '';
        if ($compact === '' || in_array($compact, ['#', 'menu'], true)) {
            return true;
        }

        return (bool) preg_match('/^\d+$/', $compact);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeClassifierJson(string $raw): ?array
    {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $raw, $matches) !== 1) {
            return null;
        }

        $decoded = json_decode((string) $matches[0], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   intent:string,
     *   confidence:float,
     *   extracted_data:array<string, mixed>,
     *   suggested_reply:?string,
     *   should_transfer_to_human:bool,
     *   reason:string
     * }
     */
    private function normalizeOutput(array $payload, string $fallbackReason): array
    {
        $intent = trim((string) ($payload['intent'] ?? 'fallback'));
        if (! in_array($intent, self::ALLOWED_INTENTS, true)) {
            $intent = 'fallback';
        }

        $confidence = is_numeric($payload['confidence'] ?? null)
            ? (float) $payload['confidence']
            : 0.0;
        $confidence = max(0.0, min(1.0, $confidence));

        $extractedData = is_array($payload['extracted_data'] ?? null)
            ? $payload['extracted_data']
            : [];

        $suggestedReply = isset($payload['suggested_reply']) && is_string($payload['suggested_reply'])
            ? trim($payload['suggested_reply'])
            : null;
        if ($suggestedReply === '') {
            $suggestedReply = null;
        }

        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '') {
            $reason = $fallbackReason;
        }

        return [
            'intent' => $intent,
            'confidence' => $confidence,
            'extracted_data' => $extractedData,
            'suggested_reply' => $suggestedReply,
            'should_transfer_to_human' => (bool) ($payload['should_transfer_to_human'] ?? false),
            'reason' => $reason,
        ];
    }

    /**
     * @return array{
     *   intent:string,
     *   confidence:float,
     *   extracted_data:array<string, mixed>,
     *   suggested_reply:?string,
     *   should_transfer_to_human:bool,
     *   reason:string
     * }
     */
    private function safeFallback(string $reason): array
    {
        return [
            'intent' => 'fallback',
            'confidence' => 0.0,
            'extracted_data' => [],
            'suggested_reply' => null,
            'should_transfer_to_human' => false,
            'reason' => $reason,
        ];
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

        return $globalProvider;
    }
}
