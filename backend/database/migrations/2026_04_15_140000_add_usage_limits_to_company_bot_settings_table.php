<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_bot_settings', function (Blueprint $table) {
            // Limits (null = unlimited)
            $table->unsignedInteger('max_users')->nullable()->after('ai_usage_limit_monthly');
            $table->unsignedInteger('max_conversation_messages_monthly')->nullable()->after('max_users');
            $table->unsignedInteger('max_template_messages_monthly')->nullable()->after('max_conversation_messages_monthly');

            // Counters (reset monthly)
            $table->unsignedInteger('conversation_messages_used')->default(0)->after('max_template_messages_monthly');
            $table->unsignedInteger('template_messages_used')->default(0)->after('conversation_messages_used');

            // Track which month the counters belong to (avoids cron dependency)
            $table->tinyInteger('usage_reset_month')->unsigned()->default(0)->after('template_messages_used');
            $table->smallInteger('usage_reset_year')->unsigned()->default(0)->after('usage_reset_month');
        });
    }

    public function down(): void
    {
        Schema::table('company_bot_settings', function (Blueprint $table) {
            $table->dropColumn([
                'max_users',
                'max_conversation_messages_monthly',
                'max_template_messages_monthly',
                'conversation_messages_used',
                'template_messages_used',
                'usage_reset_month',
                'usage_reset_year',
            ]);
        });
    }
};
