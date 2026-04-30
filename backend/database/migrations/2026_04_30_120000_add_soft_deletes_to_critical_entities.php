<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addDeletedAtColumnIfMissing('users');
        $this->addDeletedAtColumnIfMissing('companies');
        $this->addDeletedAtColumnIfMissing('conversations');
    }

    public function down(): void
    {
        $this->dropDeletedAtColumnIfExists('users');
        $this->dropDeletedAtColumnIfExists('companies');
        $this->dropDeletedAtColumnIfExists('conversations');
    }

    private function addDeletedAtColumnIfMissing(string $table): void
    {
        if (Schema::hasColumn($table, 'deleted_at')) {
            return;
        }

        Schema::table($table, function (Blueprint $table): void {
            $table->softDeletes()->index();
        });
    }

    private function dropDeletedAtColumnIfExists(string $table): void
    {
        if (! Schema::hasColumn($table, 'deleted_at')) {
            return;
        }

        Schema::table($table, function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
