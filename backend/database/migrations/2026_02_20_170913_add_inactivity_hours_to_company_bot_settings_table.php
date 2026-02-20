<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company_bot_settings', function (Blueprint $table) {
            $table->integer('inactivity_close_hours')->default(24)->after('keyword_replies');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_bot_settings', function (Blueprint $table) {
            $table->dropColumn('inactivity_close_hours');
        });
    }
};
