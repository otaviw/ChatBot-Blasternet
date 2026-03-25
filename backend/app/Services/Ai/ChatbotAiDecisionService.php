<?php

namespace App\Services\Ai;

use App\Models\Company;
use App\Models\CompanyBotSetting;

class ChatbotAiDecisionService
{
    public const MODE_DISABLED = 'disabled';
    public const MODE_ALWAYS = 'always';
    public const MODE_FALLBACK = 'fallback';
    public const MODE_OUTSIDE_BUSINESS_HOURS = 'outside_business_hours';

    /**
     * @var array<int, string>
     */
    private const ALLOWED_MODES = [
        self::MODE_DISABLED,
        self::MODE_ALWAYS,
        self::MODE_FALLBACK,
        self::MODE_OUTSIDE_BUSINESS_HOURS,
    ];

    public function shouldUseAi(Company $company): bool
    {
        $settings = $this->resolveSettings($company);
        if (! $settings instanceof CompanyBotSetting) {
            return false;
        }

        if (! (bool) $settings->ai_enabled || ! (bool) $settings->ai_chatbot_enabled) {
            return false;
        }

        return match ($this->normalizeMode((string) $settings->ai_chatbot_mode)) {
            self::MODE_ALWAYS => true,
            self::MODE_DISABLED => false,
            self::MODE_FALLBACK => false,
            self::MODE_OUTSIDE_BUSINESS_HOURS => false,
            default => false,
        };
    }

    public function getMode(Company $company): string
    {
        $settings = $this->resolveSettings($company);
        if (! $settings instanceof CompanyBotSetting) {
            return self::MODE_DISABLED;
        }

        return $this->normalizeMode((string) $settings->ai_chatbot_mode);
    }

    private function resolveSettings(Company $company): ?CompanyBotSetting
    {
        $companyId = (int) ($company->id ?? 0);
        if ($companyId <= 0) {
            return null;
        }

        if ($company->relationLoaded('botSetting')) {
            $loaded = $company->getRelation('botSetting');

            return $loaded instanceof CompanyBotSetting ? $loaded : null;
        }

        return $company->botSetting()->first();
    }

    private function normalizeMode(string $mode): string
    {
        $normalized = mb_strtolower(trim($mode));

        return in_array($normalized, self::ALLOWED_MODES, true)
            ? $normalized
            : self::MODE_DISABLED;
    }
}

