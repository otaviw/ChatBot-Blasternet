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
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('disabled_at')->nullable()->after('is_active');
        });

        DB::table('users')
            ->where('role', 'admin')
            ->update(['role' => 'system_admin']);

        DB::table('users')
            ->where('role', 'company')
            ->update(['role' => 'agent']);

        DB::table('users')
            ->where('is_active', false)
            ->whereNull('disabled_at')
            ->update(['disabled_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')
            ->where('role', 'system_admin')
            ->update(['role' => 'admin']);

        DB::table('users')
            ->where('role', 'agent')
            ->update(['role' => 'company']);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('disabled_at');
        });
    }
};
