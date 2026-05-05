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
        if (! Schema::hasTable('conversation_transfers')) {
            return;
        }

        if (
            Schema::hasColumn('conversation_transfers', 'from_assigned_type')
            && Schema::hasColumn('conversation_transfers', 'from_assigned_id')
            && Schema::hasColumn('conversation_transfers', 'to_assigned_type')
            && Schema::hasColumn('conversation_transfers', 'to_assigned_id')
        ) {
            return;
        }

        Schema::create('conversation_transfers_v2', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->enum('from_assigned_type', ['user', 'area', 'bot', 'unassigned']);
            $table->unsignedBigInteger('from_assigned_id')->nullable();
            $table->enum('to_assigned_type', ['user', 'area', 'bot', 'unassigned']);
            $table->unsignedBigInteger('to_assigned_id')->nullable();
            $table->foreignId('transferred_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['company_id', 'created_at']);
            $table->index(['to_assigned_type', 'to_assigned_id']);
        });

        $oldRows = DB::table('conversation_transfers')->orderBy('id')->get();
        foreach ($oldRows as $row) {
            $conversationId = (int) ($row->conversation_id ?? 0);
            if ($conversationId <= 0) {
                continue;
            }

            $companyId = (int) ($row->company_id ?? 0);
            if ($companyId <= 0) {
                $companyId = (int) DB::table('conversations')
                    ->where('id', $conversationId)
                    ->value('company_id');
            }
            if ($companyId <= 0) {
                continue;
            }

            [$fromType, $fromId] = $this->resolveLegacyAssignment(
                $companyId,
                $row,
                'from_assigned_type',
                'from_assigned_id',
                'from_user_id',
                'from_area'
            );

            [$toType, $toId] = $this->resolveLegacyAssignment(
                $companyId,
                $row,
                'to_assigned_type',
                'to_assigned_id',
                'to_user_id',
                'to_area'
            );

            $createdAt = $row->created_at ?? now();
            $updatedAt = $row->updated_at ?? $createdAt;

            DB::table('conversation_transfers_v2')->insert([
                'id' => (int) $row->id,
                'company_id' => $companyId,
                'conversation_id' => $conversationId,
                'from_assigned_type' => $fromType,
                'from_assigned_id' => $fromId,
                'to_assigned_type' => $toType,
                'to_assigned_id' => $toId,
                'transferred_by_user_id' => $row->transferred_by_user_id ?? null,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);
        }

        Schema::drop('conversation_transfers');
        Schema::rename('conversation_transfers_v2', 'conversation_transfers');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }

    private function resolveLegacyAssignment(
        int $companyId,
        object $row,
        string $currentTypeKey,
        string $currentIdKey,
        string $legacyUserKey,
        string $legacyAreaKey
    ): array {
        $type = trim((string) ($row->{$currentTypeKey} ?? ''));
        $id = $row->{$currentIdKey} ?? null;
        if ($type !== '') {
            return [$this->normalizeType($type), $id ? (int) $id : null];
        }

        $legacyUserId = $row->{$legacyUserKey} ?? null;
        if (! empty($legacyUserId)) {
            return ['user', (int) $legacyUserId];
        }

        $legacyArea = trim((string) ($row->{$legacyAreaKey} ?? ''));
        if ($legacyArea !== '') {
            $areaId = $this->resolveAreaId($companyId, $legacyArea);
            if ($areaId) {
                return ['area', $areaId];
            }
        }

        return ['unassigned', null];
    }

    private function normalizeType(string $type): string
    {
        $normalized = mb_strtolower(trim($type));
        if (in_array($normalized, ['user', 'area', 'bot', 'unassigned'], true)) {
            return $normalized;
        }

        return 'unassigned';
    }

    private function resolveAreaId(int $companyId, string $name): ?int
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

