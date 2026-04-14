<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $column = Schema::hasColumn('messages', 'body')
            ? 'body'
            : (Schema::hasColumn('messages', 'text') ? 'text' : null);

        if (! $column) {
            return;
        }

        $indexName = "idx_messages_{$column}_search";
        if ($this->hasIndex('messages', $indexName)) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("CREATE INDEX {$indexName} ON messages ({$column}(191))");

            return;
        }

        DB::statement("CREATE INDEX {$indexName} ON messages ({$column})");
    }

    public function down(): void
    {
        $indexNameText = 'idx_messages_text_search';
        $indexNameBody = 'idx_messages_body_search';
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            if ($this->hasIndex('messages', $indexNameText)) {
                DB::statement("DROP INDEX {$indexNameText} ON messages");
            }
            if ($this->hasIndex('messages', $indexNameBody)) {
                DB::statement("DROP INDEX {$indexNameBody} ON messages");
            }

            return;
        }

        DB::statement("DROP INDEX IF EXISTS {$indexNameText}");
        DB::statement("DROP INDEX IF EXISTS {$indexNameBody}");
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $driver = DB::getDriverName();

        return match ($driver) {
            'mysql' => collect(DB::select("SHOW INDEX FROM {$table}"))
                ->contains(fn ($row) => ($row->Key_name ?? null) === $indexName),
            'pgsql' => collect(DB::select(
                "SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?",
                [$table, $indexName]
            ))->isNotEmpty(),
            'sqlite' => collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(fn ($row) => ($row->name ?? null) === $indexName),
            default => false,
        };
    }
};
