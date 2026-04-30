<?php

declare(strict_types=1);


namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiUsage;
use App\Models\AiUsageLog;
use App\Models\CompanyBotSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AiUsageService
{
    public function logUsage(
        int $companyId,
        ?int $userId,
        ?int $conversationId,
        string $feature,
        ?string $toolUsed = null,
        ?int $tokensUsed = null
    ): AiUsage {
        $normalizedFeature = $this->normalizeFeature($feature);
        $normalizedTool = trim((string) $toolUsed);

        return AiUsage::query()->create([
            'company_id' => $companyId,
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'feature' => $normalizedFeature,
            'tokens_used' => $tokensUsed !== null ? max(0, $tokensUsed) : null,
            'tool_used' => $normalizedTool !== '' ? mb_substr($normalizedTool, 0, 120) : null,
            'created_at' => now(),
        ]);
    }

    public function countUsageByCompany(int $companyId, string $period = 'month'): int
    {
        if ($companyId <= 0) {
            return 0;
        }

        [$startAt, $endAt] = $this->resolvePeriodRange($period);

        return AiUsage::query()
            ->where('company_id', $companyId)
            ->whereBetween('created_at', [$startAt, $endAt])
            ->count();
    }

    public function consumeInternalChat(
        CompanyBotSetting $settings,
        User $user,
        AiConversation $conversation,
        string $content
    ): AiUsageLog {
        $companyId = $this->resolveCompanyId($settings, $user, $conversation);
        $this->ensureCompanySettingsExists($companyId);

        $messageLength = max(0, mb_strlen(trim($content)));

        return DB::transaction(function () use ($companyId, $user, $conversation, $messageLength): AiUsageLog {
            $lockedSettings = CompanyBotSetting::query()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first();

            if (! $lockedSettings instanceof CompanyBotSetting) {
                throw ValidationException::withMessages([
                    'ai' => ['Configuracao de IA da empresa não encontrada.'],
                ]);
            }

            if (! (bool) ($lockedSettings->ai_usage_enabled ?? true)) {
                throw ValidationException::withMessages([
                    'ai' => ['Uso de IA desabilitado para esta empresa.'],
                ]);
            }

            $monthlyLimit = $this->normalizeMonthlyLimit($lockedSettings->ai_monthly_limit);
            $monthlyLimitFromNewField = $this->normalizeMonthlyLimit($lockedSettings->ai_usage_limit_monthly);
            $usageCount = max(0, (int) $lockedSettings->ai_usage_count);

            if ($monthlyLimitFromNewField !== null
                && $this->countUsageByCompany($companyId, 'month') >= $monthlyLimitFromNewField) {
                throw ValidationException::withMessages([
                    'ai' => ['Limite de uso de IA atingido'],
                ]);
            }

            if ($monthlyLimit !== null && $usageCount >= $monthlyLimit) {
                throw ValidationException::withMessages([
                    'ai' => ['Limite de uso de IA atingido'],
                ]);
            }

            $lockedSettings->ai_usage_count = $usageCount + 1;
            $lockedSettings->save();

            return AiUsageLog::query()->create([
                'company_id' => $companyId,
                'user_id' => (int) $user->id,
                'conversation_id' => (int) $conversation->id,
                'type' => AiUsageLog::TYPE_INTERNAL_CHAT,
                'message_length' => $messageLength,
                'tokens_used' => null,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $providerResult
     */
    public function updateTokensUsed(AiUsageLog $usageLog, array $providerResult): void
    {
        $tokensUsed = $this->tokensFromProviderResult($providerResult);
        if ($tokensUsed === null) {
            return;
        }

        $usageLog->tokens_used = $tokensUsed;
        $usageLog->save();
    }

    /**
     * @param  array<string, mixed>  $providerResult
     */
    public function tokensFromProviderResult(array $providerResult): ?int
    {
        return $this->extractTokensUsed($providerResult);
    }

    private function resolveCompanyId(
        CompanyBotSetting $settings,
        User $user,
        AiConversation $conversation
    ): int {
        $settingsCompanyId = (int) ($settings->company_id ?? 0);
        if ($settingsCompanyId > 0) {
            return $settingsCompanyId;
        }

        $conversationCompanyId = (int) ($conversation->company_id ?? 0);
        if ($conversationCompanyId > 0) {
            return $conversationCompanyId;
        }

        $userCompanyId = (int) ($user->company_id ?? 0);
        if ($userCompanyId > 0) {
            return $userCompanyId;
        }

        throw ValidationException::withMessages([
            'ai' => ['Não foi possível identificar a empresa para controle de uso da IA.'],
        ]);
    }

    private function ensureCompanySettingsExists(int $companyId): void
    {
        CompanyBotSetting::query()->firstOrCreate(
            ['company_id' => $companyId],
            [
                'ai_enabled' => false,
                'ai_internal_chat_enabled' => false,
                'ai_usage_enabled' => true,
                'ai_usage_limit_monthly' => null,
                'ai_chatbot_enabled' => false,
                'ai_max_context_messages' => 10,
                'ai_usage_count' => 0,
                'ai_chatbot_mode' => 'disabled',
                'ai_chatbot_rules' => null,
            ]
        );
    }

    private function normalizeFeature(string $feature): string
    {
        $normalized = mb_strtolower(trim($feature));
        if (in_array($normalized, AiUsage::ALLOWED_FEATURES, true)) {
            return $normalized;
        }

        return AiUsage::FEATURE_INTERNAL_CHAT;
    }

    private function normalizeMonthlyLimit(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $limit = (int) $value;

        return max(0, $limit);
    }

    /**
     * @return array{0:Carbon,1:Carbon}
     */
    private function resolvePeriodRange(string $period): array
    {
        $normalized = mb_strtolower(trim($period));
        $now = now();

        return match ($normalized) {
            'day', 'daily' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week', 'weekly' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
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
            if (! is_numeric($candidate)) {
                continue;
            }

            $value = (int) $candidate;
            if ($value >= 0) {
                return $value;
            }
        }

        return null;
    }
}
