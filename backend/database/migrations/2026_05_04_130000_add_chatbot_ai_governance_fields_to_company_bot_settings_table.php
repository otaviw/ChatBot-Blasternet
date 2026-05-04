<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_bot_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('company_bot_settings', 'ai_chatbot_enabled')) {
                $table->boolean('ai_chatbot_enabled')
                    ->default(false)
                    ->after('ai_internal_chat_enabled');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_chatbot_shadow_mode')) {
                $table->boolean('ai_chatbot_shadow_mode')
                    ->default(false)
                    ->after('ai_chatbot_enabled');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_chatbot_sandbox_enabled')) {
                $table->boolean('ai_chatbot_sandbox_enabled')
                    ->default(false)
                    ->after('ai_chatbot_shadow_mode');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_chatbot_confidence_threshold')) {
                $table->decimal('ai_chatbot_confidence_threshold', 4, 2)
                    ->default(0.75)
                    ->after('ai_chatbot_sandbox_enabled');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_chatbot_handoff_repeat_limit')) {
                $table->unsignedTinyInteger('ai_chatbot_handoff_repeat_limit')
                    ->default(2)
                    ->after('ai_chatbot_confidence_threshold');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_bot_settings', function (Blueprint $table) {
            if (Schema::hasColumn('company_bot_settings', 'ai_chatbot_handoff_repeat_limit')) {
                $table->dropColumn('ai_chatbot_handoff_repeat_limit');
            }

            if (Schema::hasColumn('company_bot_settings', 'ai_chatbot_confidence_threshold')) {
                $table->dropColumn('ai_chatbot_confidence_threshold');
            }

            if (Schema::hasColumn('company_bot_settings', 'ai_chatbot_sandbox_enabled')) {
                $table->dropColumn('ai_chatbot_sandbox_enabled');
            }

            if (Schema::hasColumn('company_bot_settings', 'ai_chatbot_shadow_mode')) {
                $table->dropColumn('ai_chatbot_shadow_mode');
            }
        });
    }
};
