<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_bot_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('unattended_alert_hours')->nullable()->after('inactivity_close_hours');
        });
    }

    public function down(): void
    {
        Schema::table('company_bot_settings', function (Blueprint $table) {
            $table->dropColumn('unattended_alert_hours');
        });
    }
};
