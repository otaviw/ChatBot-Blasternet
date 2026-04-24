<?php

namespace App\Services\Bot;

use App\Models\Area;

class BotSettingsSupportService
{
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
