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
            if (! Schema::hasColumn('company_bot_settings', 'ai_usage_enabled')) {
                $table->boolean('ai_usage_enabled')->default(true)->after('ai_internal_chat_enabled');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_usage_limit_monthly')) {
                $table->unsignedInteger('ai_usage_limit_monthly')->nullable()->after('ai_usage_enabled');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_chatbot_enabled')) {
                $table->boolean('ai_chatbot_enabled')->default(false)->after('ai_internal_chat_enabled');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_chatbot_auto_reply_enabled')) {
                $table->boolean('ai_chatbot_auto_reply_enabled')->default(false)->after('ai_chatbot_enabled');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_chatbot_rules')) {
                $table->json('ai_chatbot_rules')->nullable()->after('ai_chatbot_auto_reply_enabled');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_bot_settings', function (Blueprint $table) {
            if (Schema::hasColumn('company_bot_settings', 'ai_chatbot_rules')) {
                $table->dropColumn('ai_chatbot_rules');
            }

            if (Schema::hasColumn('company_bot_settings', 'ai_usage_limit_monthly')) {
                $table->dropColumn('ai_usage_limit_monthly');
            }

            if (Schema::hasColumn('company_bot_settings', 'ai_usage_enabled')) {
                $table->dropColumn('ai_usage_enabled');
            }
        });
    }
};

