<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\CompanyBotSetting;
use App\Models\Conversation;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

class ChatbotAiPolicyService
{
    public const ACTION_FALLBACK_LEGACY = 'fallback_legacy';
    public const ACTION_SUGGEST_REPLY = 'suggest_reply';
    public const ACTION_EXTRACT_ONLY = 'extract_only';
    public const ACTION_HANDOFF = 'handoff';
    public const ACTION_ROUTE_TO_APPOINTMENT_FLOW = 'route_to_appointment_flow';

    /**
     * @var array<int, string>
     */
    private const ALLOWED_ACTIONS = [
        self::ACTION_FALLBACK_LEGACY,
        self::ACTION_SUGGEST_REPLY,
        self::ACTION_EXTRACT_ONLY,
        self::ACTION_HANDOFF,
        self::ACTION_ROUTE_TO_APPOINTMENT_FLOW,
    ];

    /**
     * Fallback em memória para ambientes sem cache configurado.
     *
     * @var array<string, int>
     */
    private static array $memoryCounters = [];

    public function __construct(
        private readonly ?CacheRepository $cache = null,
    ) {}

    /**
     * @param  array<string, mixed>  $classification
     * @param  array<string, mixed>  $context
     * @return array{
     *   action:string,
     *   reason:string,
     *   handoff_reason:?string,
     *   should_transfer_to_human:bool,
     *   intent:string,
     *   confidence:float,
     *   threshold:float,
     *   attendant_request_count:int,
     *   attendant_request_limit:int
     * }
     */
    public function decide(
        Conversation $conversation,
        CompanyBotSetting $settings,
        array $classification,
        array $context = []
    ): array {
        try {
            $intent = $this->normalizeIntent($classification['intent'] ?? null);
            $confidence = $this->normalizeConfidence($classification['confidence'] ?? null);
            $threshold = $this->resolveThreshold($settings);
            $handoffLimit = $this->resolveHandoffRepeatLimit($settings);
            $shouldTransfer = (bool) ($classification['should_transfer_to_human'] ?? false);
            $reason = $this->normalizeReason($classification['reason'] ?? null);
            $attendantRequestCount = 0;

            if ($confidence < $threshold) {
                return $this->decision(
                    self::ACTION_FALLBACK_LEGACY,
                    'low_confidence',
                    $intent,
                    $confidence,
                    $threshold,
                    $attendantRequestCount,
                    $handoffLimit,
                    false,
                    null
                );
            }

            if ($this->isSensitiveOrUnsafe($classification, $context)) {
                return $this->decision(
                    self::ACTION_HANDOFF,
                    'sensitive_or_unsafe_topic',
                    $intent,
                    $confidence,
                    $threshold,
                    $attendantRequestCount,
                    $handoffLimit,
                    true,
                    'sensitive_or_unsafe_topic'
                );
            }

            if ($intent === 'falar_com_atendente') {
                $attendantRequestCount = $this->incrementAttendantRequestCount($conversation);

                if ($attendantRequestCount >= $handoffLimit) {
                    return $this->decision(
                        self::ACTION_HANDOFF,
                        'repeated_human_request_limit_reached',
                        $intent,
                        $confidence,
                        $threshold,
                        $attendantRequestCount,
                        $handoffLimit,
                        true,
                        'repeated_human_request_limit_reached'
                    );
                }

                $quickAssistAllowed = (bool) ($settings->ai_chatbot_auto_reply_enabled ?? false);
                if ($quickAssistAllowed) {
                    return $this->decision(
                        self::ACTION_SUGGEST_REPLY,
                        'first_human_request_quick_assist_allowed',
                        $intent,
                        $confidence,
                        $threshold,
                        $attendantRequestCount,
                        $handoffLimit,
                        false,
                        null
                    );
                }

                return $this->decision(
                    self::ACTION_HANDOFF,
                    'quick_assist_not_allowed',
                    $intent,
                    $confidence,
                    $threshold,
                    $attendantRequestCount,
                    $handoffLimit,
                    true,
                    'quick_assist_not_allowed'
                );
            }

            if (in_array($intent, ['agendamento', 'remarcar_agendamento', 'cancelar_agendamento'], true)) {
                return $this->decision(
                    self::ACTION_ROUTE_TO_APPOINTMENT_FLOW,
                    'appointment_intent_detected',
                    $intent,
                    $confidence,
                    $threshold,
                    $attendantRequestCount,
                    $handoffLimit,
                    false,
                    null
                );
            }

            if ($intent === 'duvida_geral') {
                return $this->decision(
                    self::ACTION_SUGGEST_REPLY,
                    'general_question_high_confidence',
                    $intent,
                    $confidence,
                    $threshold,
                    $attendantRequestCount,
                    $handoffLimit,
                    $shouldTransfer,
                    $shouldTransfer ? $reason : null
                );
            }

            if ($intent === 'menu' || $intent === 'fallback') {
                return $this->decision(
                    self::ACTION_FALLBACK_LEGACY,
                    'legacy_menu_or_fallback_intent',
                    $intent,
                    $confidence,
                    $threshold,
                    $attendantRequestCount,
                    $handoffLimit,
                    false,
                    null
                );
            }

            if (in_array($intent, ['suporte_tecnico', 'financeiro'], true)) {
                if ($this->isSandboxMode($context)) {
                    return $this->decision(
                        self::ACTION_SUGGEST_REPLY,
                        'specialized_intent_sandbox_assist',
                        $intent,
                        $confidence,
                        $threshold,
                        $attendantRequestCount,
                        $handoffLimit,
                        $shouldTransfer,
                        $shouldTransfer ? $reason : null
                    );
                }

                return $this->decision(
                    self::ACTION_EXTRACT_ONLY,
                    'specialized_intent_extract_only',
                    $intent,
                    $confidence,
                    $threshold,
                    $attendantRequestCount,
                    $handoffLimit,
                    $shouldTransfer,
                    $shouldTransfer ? $reason : null
                );
            }

            return $this->decision(
                self::ACTION_FALLBACK_LEGACY,
                'unknown_intent',
                $intent,
                $confidence,
                $threshold,
                $attendantRequestCount,
                $handoffLimit,
                false,
                null
            );
        } catch (Throwable $exception) {
            $threshold = $this->resolveThreshold($settings);
            $handoffLimit = $this->resolveHandoffRepeatLimit($settings);

            return $this->decision(
                self::ACTION_FALLBACK_LEGACY,
                'policy_exception',
                $this->normalizeIntent($classification['intent'] ?? null),
                $this->normalizeConfidence($classification['confidence'] ?? null),
                $threshold,
                0,
                $handoffLimit,
                false,
                null
            );
        }
    }

    /**
     * @return array{
     *   action:string,
     *   reason:string,
     *   handoff_reason:?string,
     *   should_transfer_to_human:bool,
     *   intent:string,
     *   confidence:float,
     *   threshold:float,
     *   attendant_request_count:int,
     *   attendant_request_limit:int
     * }
     */
    private function decision(
        string $action,
        string $reason,
        string $intent,
        float $confidence,
        float $threshold,
        int $attendantRequestCount,
        int $handoffLimit,
        bool $shouldTransferToHuman,
        ?string $handoffReason
    ): array {
        $normalizedAction = in_array($action, self::ALLOWED_ACTIONS, true)
            ? $action
            : self::ACTION_FALLBACK_LEGACY;

        return [
            'action' => $normalizedAction,
            'reason' => $reason,
            'handoff_reason' => $handoffReason,
            'should_transfer_to_human' => $shouldTransferToHuman,
            'intent' => $intent,
            'confidence' => $confidence,
            'threshold' => $threshold,
            'attendant_request_count' => max(0, $attendantRequestCount),
            'attendant_request_limit' => max(1, $handoffLimit),
        ];
    }

    private function resolveThreshold(CompanyBotSetting $settings): float
    {
        $value = is_numeric($settings->ai_chatbot_confidence_threshold ?? null)
            ? (float) $settings->ai_chatbot_confidence_threshold
            : 0.75;

        return max(0.0, min(1.0, $value));
    }

    private function resolveHandoffRepeatLimit(CompanyBotSetting $settings): int
    {
        $value = is_numeric($settings->ai_chatbot_handoff_repeat_limit ?? null)
            ? (int) $settings->ai_chatbot_handoff_repeat_limit
            : 2;

        return max(1, $value);
    }

    private function normalizeIntent(mixed $raw): string
    {
        $intent = mb_strtolower(trim((string) $raw));

        return $intent !== '' ? $intent : 'fallback';
    }

    private function normalizeConfidence(mixed $raw): float
    {
        $value = is_numeric($raw) ? (float) $raw : 0.0;

        return max(0.0, min(1.0, $value));
    }

    private function normalizeReason(mixed $raw): string
    {
        $reason = mb_strtolower(trim((string) $raw));

        return $reason !== '' ? $reason : 'unspecified_reason';
    }

    /**
     * @param  array<string, mixed>  $classification
     * @param  array<string, mixed>  $context
     */
    private function isSensitiveOrUnsafe(array $classification, array $context): bool
    {
        $flags = [];

        $reason = $this->normalizeReason($classification['reason'] ?? null);
        $flags[] = $reason;

        $keywordsFromData = is_array($classification['extracted_data'] ?? null)
            ? $classification['extracted_data']
            : [];

        if (is_string($keywordsFromData['safety'] ?? null)) {
            $flags[] = mb_strtolower(trim((string) $keywordsFromData['safety']));
        }

        if (is_string($context['message_text'] ?? null)) {
            $flags[] = mb_strtolower(trim((string) $context['message_text']));
        }

        $joined = implode(' | ', array_filter($flags, fn (string $item): bool => $item !== ''));
        if ($joined === '') {
            return false;
        }

        $riskMarkers = [
            'sensivel',
            'sensitive',
            'critico',
            'critical',
            'unsafe',
            'inseguro',
            'suicid',
            'amea',
            'violencia',
            'violence',
            'unknown',
            'desconhecido',
        ];

        foreach ($riskMarkers as $marker) {
            if (str_contains($joined, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function isSandboxMode(array $context): bool
    {
        return mb_strtolower(trim((string) ($context['mode'] ?? ''))) === 'sandbox';
    }

    private function incrementAttendantRequestCount(Conversation $conversation): int
    {
        $key = $this->attendantCounterKey($conversation);
        if ($key === null) {
            return 1;
        }

        if ($this->cache instanceof CacheRepository) {
            try {
                $this->cache->add($key, 0, now()->addHours(24));
                $next = (int) $this->cache->increment($key);

                return max(1, $next);
            } catch (Throwable) {
            }
        }

        $current = (int) (self::$memoryCounters[$key] ?? 0);
        $next = $current + 1;
        self::$memoryCounters[$key] = $next;

        return $next;
    }

    private function attendantCounterKey(Conversation $conversation): ?string
    {
        $conversationId = (int) ($conversation->id ?? 0);
        if ($conversationId <= 0) {
            return null;
        }

        $flow = mb_strtolower(trim((string) ($conversation->bot_flow ?? 'main')));
        if ($flow === '') {
            $flow = 'main';
        }

        $step = mb_strtolower(trim((string) ($conversation->bot_step ?? 'menu')));
        if ($step === '') {
            $step = 'menu';
        }

        return "chatbot_ai:attendant_requests:conversation:{$conversationId}:{$flow}:{$step}";
    }
}
