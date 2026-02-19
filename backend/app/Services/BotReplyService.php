<?php

namespace App\Services;

use App\Models\Company;
use Carbon\Carbon;

class BotReplyService
{
    public function buildReply(?Company $company, string $customerText, bool $isFirstInboundMessage = false): string
    {
        if (! $company) {
            return $this->defaultFallback();
        }

        $settings = $company->botSetting;
        $isActive = $settings?->is_active ?? true;
        if (! $isActive) {
            return $this->defaultFallback();
        }

        $timezone = $settings?->timezone ?? 'America/Sao_Paulo';
        $businessHours = $settings?->business_hours ?? $this->defaultBusinessHours();
        $outOfHoursMessage = $settings?->out_of_hours_message ?: $this->defaultOutOfHours();
        $welcomeMessage = $settings?->welcome_message ?: $this->defaultWelcome();
        $fallbackMessage = $settings?->fallback_message ?: $this->defaultFallback();
        $keywordReplies = $settings?->keyword_replies ?? [];

        if (! $this->isWithinBusinessHours($businessHours, $timezone)) {
            return $outOfHoursMessage;
        }

        foreach ($keywordReplies as $keywordReply) {
            $keyword = mb_strtolower(trim((string) ($keywordReply['keyword'] ?? '')));
            $reply = trim((string) ($keywordReply['reply'] ?? ''));
            if ($keyword === '' || $reply === '') {
                continue;
            }

            if (str_contains(mb_strtolower($customerText), $keyword)) {
                return $reply;
            }
        }

        if ($isFirstInboundMessage) {
            return $welcomeMessage;
        }

        return $fallbackMessage;
    }

    private function isWithinBusinessHours(array $hours, ?string $timezone): bool
    {
        $tz = $timezone ?: 'America/Sao_Paulo';
        $now = Carbon::now($tz);
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
        if (! $dayKey) {
            return false;
        }

        $dayConfig = $hours[$dayKey] ?? null;
        if (! is_array($dayConfig) || ! ($dayConfig['enabled'] ?? false)) {
            return false;
        }

        $start = $dayConfig['start'] ?? null;
        $end = $dayConfig['end'] ?? null;
        if (! is_string($start) || ! is_string($end)) {
            return false;
        }

        $current = $now->format('H:i');

        return $current >= $start && $current <= $end;
    }

    private function defaultFallback(): string
    {
        return 'Nao entendi sua mensagem. Pode reformular?';
    }

    private function defaultOutOfHours(): string
    {
        return 'Estamos fora do horario de atendimento no momento.';
    }

    private function defaultWelcome(): string
    {
        return 'Oi. Como posso ajudar?';
    }

    private function defaultBusinessHours(): array
    {
        return [
            'monday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
            'tuesday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
            'wednesday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
            'thursday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
            'friday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
            'saturday' => ['enabled' => false, 'start' => null, 'end' => null],
            'sunday' => ['enabled' => false, 'start' => null, 'end' => null],
        ];
    }
}
