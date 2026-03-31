<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A migration original (2026_03_30_180937) foi registrada na tabela migrations
     * mas o DDL nunca foi executado no banco. Esta migration corrige isso de forma
     * idempotente usando Schema::hasColumn para não duplicar a coluna caso ela já exista.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('messages', 'media_filename')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->string('media_filename')->nullable()->after('media_mime_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('messages', 'media_filename')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropColumn('media_filename');
            });
        }
    }
};
