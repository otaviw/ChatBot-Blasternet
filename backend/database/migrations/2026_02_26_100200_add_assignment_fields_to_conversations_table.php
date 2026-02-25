<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->enum('assigned_type', ['user', 'area', 'bot', 'unassigned'])
                ->default('unassigned')
                ->after('status');
            $table->unsignedBigInteger('assigned_id')->nullable()->after('assigned_type');
            $table->foreignId('current_area_id')
                ->nullable()
                ->after('assigned_id')
                ->constrained('areas')
                ->nullOnDelete();

            $table->index(['assigned_type', 'assigned_id']);
            $table->index('current_area_id');
        });

        $rows = DB::table('conversations')
            ->select('id', 'company_id', 'handling_mode', 'assigned_user_id', 'assigned_area')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $legacyMode = trim((string) ($row->handling_mode ?? ''));
            $handlingMode = $legacyMode === 'bot' ? 'bot' : 'human';

            $assignedType = 'unassigned';
            $assignedId = null;
            $currentAreaId = null;

            if (! empty($row->assigned_user_id)) {
                $assignedType = 'user';
                $assignedId = (int) $row->assigned_user_id;
            } else {
                $areaId = $this->resolveAreaId((int) ($row->company_id ?? 0), $row->assigned_area ?? null);
                if ($areaId) {
                    $assignedType = 'area';
                    $assignedId = $areaId;
                    $currentAreaId = $areaId;
                } elseif ($handlingMode === 'bot') {
                    $assignedType = 'bot';
                }
            }

            DB::table('conversations')
                ->where('id', $row->id)
                ->update([
                    'handling_mode' => $handlingMode,
                    'assigned_type' => $assignedType,
                    'assigned_id' => $assignedId,
                    'current_area_id' => $currentAreaId,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $rows = DB::table('conversations')
            ->select('id', 'handling_mode')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $legacyMode = trim((string) ($row->handling_mode ?? ''));
            $restoredMode = $legacyMode === 'bot' ? 'bot' : 'manual';
            DB::table('conversations')
                ->where('id', $row->id)
                ->update(['handling_mode' => $restoredMode]);
        }

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['assigned_type', 'assigned_id']);
            $table->dropIndex(['current_area_id']);
            $table->dropConstrainedForeignId('current_area_id');
            $table->dropColumn(['assigned_type', 'assigned_id']);
        });
    }

    private function resolveAreaId(int $companyId, ?string $name): ?int
    {
        $label = trim((string) $name);
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

