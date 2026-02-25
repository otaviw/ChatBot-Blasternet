<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('areas') || ! Schema::hasTable('companies')) {
            return;
        }

        $this->syncAreasFromCompanySettings();
        $this->syncUserAreaPivotFromLegacyColumn();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Sem rollback automatico de dados de relacionamento.
    }

    private function syncAreasFromCompanySettings(): void
    {
        if (! Schema::hasTable('company_bot_settings') || ! Schema::hasColumn('company_bot_settings', 'service_areas')) {
            return;
        }

        $settings = DB::table('company_bot_settings')
            ->select('company_id', 'service_areas')
            ->get();

        foreach ($settings as $setting) {
            $companyId = (int) ($setting->company_id ?? 0);
            if ($companyId <= 0) {
                continue;
            }

            $areas = $this->decodeAreaList($setting->service_areas);
            foreach ($areas as $areaName) {
                $this->firstOrCreateArea($companyId, $areaName);
            }
        }
    }

    private function syncUserAreaPivotFromLegacyColumn(): void
    {
        if (! Schema::hasColumn('users', 'areas')) {
            return;
        }

        $users = DB::table('users')
            ->select('id', 'company_id', 'areas')
            ->whereNotNull('company_id')
            ->get();

        foreach ($users as $user) {
            $companyId = (int) ($user->company_id ?? 0);
            if ($companyId <= 0) {
                continue;
            }

            $areas = $this->decodeAreaList($user->areas);
            foreach ($areas as $areaName) {
                $areaId = $this->firstOrCreateArea($companyId, $areaName);
                if (! $areaId) {
                    continue;
                }

                $exists = DB::table('area_user')
                    ->where('area_id', $areaId)
                    ->where('user_id', (int) $user->id)
                    ->exists();

                if (! $exists) {
                    DB::table('area_user')->insert([
                        'area_id' => $areaId,
                        'user_id' => (int) $user->id,
                    ]);
                }
            }
        }
    }

    private function decodeAreaList(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_array($raw)) {
            return $this->normalizeAreaList($raw);
        }

        $decoded = json_decode((string) $raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return $this->normalizeAreaList($decoded);
    }

    private function normalizeAreaList(array $areas): array
    {
        $normalized = [];
        $seen = [];

        foreach ($areas as $area) {
            $name = trim((string) $area);
            if ($name === '') {
                continue;
            }

            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $name;
        }

        return $normalized;
    }

    private function firstOrCreateArea(int $companyId, string $name): ?int
    {
        $label = trim($name);
        if ($companyId <= 0 || $label === '') {
            return null;
        }

        $existing = DB::table('areas')
            ->where('company_id', $companyId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($label)])
            ->first(['id']);

        if ($existing) {
            return (int) $existing->id;
        }

        return (int) DB::table('areas')->insertGetId([
            'company_id' => $companyId,
            'name' => $label,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};

