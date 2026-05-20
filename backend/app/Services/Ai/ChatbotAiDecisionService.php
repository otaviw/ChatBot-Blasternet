<?php

declare(strict_types=1);


namespace App\Services\Ai;

use App\Models\Company;
use App\Models\CompanyBotSetting;
use Carbon\Carbon;

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
            self::MODE_FALLBACK => true,
            self::MODE_OUTSIDE_BUSINESS_HOURS => ! $this->isWithinBusinessHours($settings),
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

    private function isWithinBusinessHours(CompanyBotSetting $settings): bool
    {
        $timezone = trim((string) ($settings->timezone ?? 'America/Sao_Paulo'));
        if ($timezone === '') {
            $timezone = 'America/Sao_Paulo';
        }

        $hours = is_array($settings->business_hours) ? $settings->business_hours : [];
        $now = Carbon::now($timezone);
        $dayMap = [
            'Monday' => 'monday',
            'Tuesday' => 'tuesday',
            'Wednesday' => 'wednesday',
            'Thursday' => 'thursday',
            'Friday' => 'friday',
            'Saturday' => 'saturday',
            'Sunday' => 'sunday',
        ];
        $dayKey = $dayMap[$now->format('l')] ?? null;
        if ($dayKey === null) {
            return false;
        }

        $dayConfig = is_array($hours[$dayKey] ?? null) ? $hours[$dayKey] : null;
        if (! is_array($dayConfig) || ! (bool) ($dayConfig['enabled'] ?? false)) {
            return false;
        }

        $start = is_string($dayConfig['start'] ?? null) ? trim((string) $dayConfig['start']) : '';
        $end = is_string($dayConfig['end'] ?? null) ? trim((string) $dayConfig['end']) : '';
        if ($start === '' || $end === '') {
            return false;
        }

        $current = $now->format('H:i');

        return $current >= $start && $current <= $end;
    }
}

