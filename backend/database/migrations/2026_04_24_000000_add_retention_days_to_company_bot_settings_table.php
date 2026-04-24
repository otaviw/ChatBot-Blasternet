<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_bot_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('message_retention_days')->nullable()->after('unattended_alert_hours');
            $table->unsignedSmallInteger('ai_usage_log_retention_days')->nullable()->after('message_retention_days');
        });
    }

    public function down(): void
    {
        Schema::table('company_bot_settings', function (Blueprint $table) {
            $table->dropColumn(['message_retention_days', 'ai_usage_log_retention_days']);
        });
    }
};
