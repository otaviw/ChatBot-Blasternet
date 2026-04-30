<?php

declare(strict_types=1);


namespace App\Services\Bot;

use App\Models\Area;

class BotSettingsSupportService
{
    /**
     * Payload completo de defaults para um CompanyBotSetting novo.
     * Fonte única de verdade — use em todo lugar que precise inicializar settings.
     *
     * @return array<string, mixed>
     */
    public function defaultBotSettingsPayload(int $companyId): array
    {
        return [
            'company_id'                        => $companyId,
            'is_active'                         => true,
            'timezone'                          => 'America/Sao_Paulo',
            'welcome_message'                   => 'Oi. Como posso ajudar?',
            'fallback_message'                  => 'Não entendi sua mensagem. Pode reformular?',
            'out_of_hours_message'              => 'Estamos fora do horario de atendimento no momento.',
            'business_hours'                    => $this->defaultBusinessHours(),
            'keyword_replies'                   => [],
            'service_areas'                     => [],
            'stateful_menu_flow'                => null,
            'inactivity_close_hours'            => 24,
            'message_retention_days'            => 180,
            'ai_enabled'                        => false,
            'ai_internal_chat_enabled'          => false,
            'ai_usage_enabled'                  => true,
            'ai_usage_limit_monthly'            => null,
            'max_users'                         => null,
            'max_conversation_messages_monthly' => null,
            'max_template_messages_monthly'     => null,
            'ai_chatbot_enabled'                => false,
            'ai_chatbot_auto_reply_enabled'     => false,
            'ai_chatbot_mode'                   => 'disabled',
            'ai_chatbot_rules'                  => null,
            'ai_persona'                        => null,
            'ai_tone'                           => null,
            'ai_language'                       => null,
            'ai_formality'                      => null,
            'ai_system_prompt'                  => null,
            'ai_max_context_messages'           => 10,
            'ai_temperature'                    => null,
            'ai_max_response_tokens'            => null,
            'ai_provider'                       => null,
            'ai_model'                          => null,
        ];
    }

    public function defaultBusinessHours(): array
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

    public function normalizeBusinessHours(array $hours): array
    {
        $defaults = $this->defaultBusinessHours();

        foreach ($defaults as $day => $defaultValue) {
            $current = $hours[$day] ?? [];
            $defaults[$day] = [
                'enabled' => (bool) ($current['enabled'] ?? false),
                'start' => $current['start'] ?? null,
                'end' => $current['end'] ?? null,
            ];
        }

        return $defaults;
    }

    public function normalizeKeywordReplies(array $replies): array
    {
        $normalized = [];

        foreach ($replies as $item) {
            $keyword = trim((string) ($item['keyword'] ?? ''));
            $reply = trim((string) ($item['reply'] ?? ''));
            if ($keyword === '' || $reply === '') {
                continue;
            }

            $normalized[] = [
                'keyword' => $keyword,
                'reply' => $reply,
            ];
        }

        return $normalized;
    }

    public function normalizeServiceAreas(array $areas): array
    {
        $normalized = [];
        $seen = [];

        foreach ($areas as $area) {
            $label = trim((string) $area);
            if ($label === '') {
                continue;
            }

            $key = mb_strtolower($label);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $label;
        }

        return $normalized;
    }

    public function resolveInactivityCloseHours(mixed $requestedValue, mixed $existingValue): int
    {
        if ($requestedValue !== null) {
            return (int) $requestedValue;
        }

        if (is_numeric($existingValue)) {
            $hours = (int) $existingValue;
            if ($hours >= 1 && $hours <= 720) {
                return $hours;
            }
        }

        return 24;
    }

    public function syncServiceAreas(int $companyId, array $areaNames): void
    {
        $names = collect($areaNames)
            ->map(fn($name) => trim((string) $name))
            ->filter()
            ->unique(fn($name) => mb_strtolower($name))
            ->values();

        $areas = Area::query()
            ->where('company_id', $companyId)
            ->get();

        $keepIds = [];
        foreach ($names as $name) {
            $existing = $areas->first(
                fn(Area $area) => mb_strtolower(trim((string) $area->name)) === mb_strtolower((string) $name)
            );

            if ($existing) {
                if ($existing->name !== $name) {
                    $existing->name = (string) $name;
                    $existing->save();
                }
                $keepIds[] = $existing->id;
                continue;
            }

            $created = Area::create([
                'company_id' => $companyId,
                'name' => (string) $name,
            ]);
            $keepIds[] = $created->id;
        }

        if ($keepIds === []) {
            return;
        }

        Area::query()
            ->where('company_id', $companyId)
            ->whereNotIn('id', $keepIds)
            ->whereDoesntHave('currentConversations')
            ->whereDoesntHave('users')
            ->delete();
    }
}
