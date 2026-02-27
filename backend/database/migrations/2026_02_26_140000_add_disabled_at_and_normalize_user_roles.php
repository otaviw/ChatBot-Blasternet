<?php

use App\Models\User;
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
            ->where('role', User::ROLE_LEGACY_ADMIN)
            ->update(['role' => User::ROLE_SYSTEM_ADMIN]);

        DB::table('users')
            ->where('role', User::ROLE_LEGACY_COMPANY)
            ->update(['role' => User::ROLE_AGENT]);

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
            ->where('role', User::ROLE_SYSTEM_ADMIN)
            ->update(['role' => User::ROLE_LEGACY_ADMIN]);

        DB::table('users')
            ->where('role', User::ROLE_AGENT)
            ->update(['role' => User::ROLE_LEGACY_COMPANY]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('disabled_at');
        });
    }
};
