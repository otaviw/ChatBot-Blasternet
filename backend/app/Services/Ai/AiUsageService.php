<?php

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiUsageLog;
use App\Models\CompanyBotSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AiUsageService
{
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
                    'ai' => ['Configuracao de IA da empresa nao encontrada.'],
                ]);
            }

            $monthlyLimit = $this->normalizeMonthlyLimit($lockedSettings->ai_monthly_limit);
            $usageCount = max(0, (int) $lockedSettings->ai_usage_count);

            if ($monthlyLimit !== null && $usageCount >= $monthlyLimit) {
                throw ValidationException::withMessages([
                    'ai' => ['Limite mensal de uso de IA da empresa atingido.'],
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
        $tokensUsed = $this->extractTokensUsed($providerResult);
        if ($tokensUsed === null) {
            return;
        }

        $usageLog->tokens_used = $tokensUsed;
        $usageLog->save();
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
            'ai' => ['Nao foi possivel identificar a empresa para controle de uso da IA.'],
        ]);
    }

    private function ensureCompanySettingsExists(int $companyId): void
    {
        CompanyBotSetting::query()->firstOrCreate(
            ['company_id' => $companyId],
            [
                'ai_enabled' => false,
                'ai_internal_chat_enabled' => false,
                'ai_chatbot_enabled' => false,
                'ai_max_context_messages' => 10,
                'ai_usage_count' => 0,
                'ai_chatbot_mode' => 'disabled',
            ]
        );
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

