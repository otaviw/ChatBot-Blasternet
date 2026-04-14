<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_company_knowledge', function (Blueprint $table) {
            $table->string('indexing_status', 20)->default('pending')->after('is_active');
            $table->timestamp('indexed_at')->nullable()->after('indexing_status');
        });

        // Mark existing records as already indexed (they've been processed before this migration)
        DB::table('ai_company_knowledge')->update(['indexing_status' => 'indexed']);
    }

    public function down(): void
    {
        Schema::table('ai_company_knowledge', function (Blueprint $table) {
            $table->dropColumn(['indexing_status', 'indexed_at']);
        });
    }
};
