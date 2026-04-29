<?php

namespace App\Support\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class ForwardOnlyMigration extends Migration
{
    /**
     * Executa indexacao de forma idempotente para deploys repetiveis.
     *
     * @param  array<int, string>  $columns
     */
    protected function addIndexIfMissing(string $tableName, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        if ($this->hasIndex($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }

    /**
     * Rollback logico: migrations de producao nao removem schema em down().
     */
    protected function forwardOnlyDown(): void
    {
        // No-op intencional.
    }

    protected function hasIndex(string $tableName, string $indexName): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $row = DB::selectOne(
                'SELECT 1
                 FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                   AND table_name = ?
                   AND index_name = ?
                 LIMIT 1',
                [$tableName, $indexName]
            );

            return $row !== null;
        }

        if ($driver === 'pgsql') {
            $row = DB::selectOne(
                'SELECT 1
                 FROM pg_indexes
                 WHERE schemaname = current_schema()
                   AND tablename = ?
                   AND indexname = ?
                 LIMIT 1',
                [$tableName, $indexName]
            );

            return $row !== null;
        }

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$tableName}')");
            foreach ($indexes as $index) {
                $name = (string) ($index->name ?? '');
                if ($name === $indexName) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
}
