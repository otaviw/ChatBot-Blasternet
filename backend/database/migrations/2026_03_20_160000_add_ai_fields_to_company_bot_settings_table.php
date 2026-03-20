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
            if (! Schema::hasColumn('company_bot_settings', 'ai_enabled')) {
                $table->boolean('ai_enabled')->default(false)->after('is_active');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_internal_chat_enabled')) {
                $table->boolean('ai_internal_chat_enabled')->default(false)->after('ai_enabled');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_chatbot_auto_reply_enabled')) {
                $table->boolean('ai_chatbot_auto_reply_enabled')->default(false)->after('ai_internal_chat_enabled');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_provider')) {
                $table->string('ai_provider', 60)->nullable()->after('ai_chatbot_auto_reply_enabled');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_model')) {
                $table->string('ai_model', 120)->nullable()->after('ai_provider');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_system_prompt')) {
                $table->text('ai_system_prompt')->nullable()->after('ai_model');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_temperature')) {
                $table->decimal('ai_temperature', 4, 2)->nullable()->after('ai_system_prompt');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_max_response_tokens')) {
                $table->unsignedInteger('ai_max_response_tokens')->nullable()->after('ai_temperature');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_bot_settings', function (Blueprint $table) {
            if (Schema::hasColumn('company_bot_settings', 'ai_max_response_tokens')) {
                $table->dropColumn('ai_max_response_tokens');
            }

            if (Schema::hasColumn('company_bot_settings', 'ai_temperature')) {
                $table->dropColumn('ai_temperature');
            }

            if (Schema::hasColumn('company_bot_settings', 'ai_system_prompt')) {
                $table->dropColumn('ai_system_prompt');
            }

            if (Schema::hasColumn('company_bot_settings', 'ai_model')) {
                $table->dropColumn('ai_model');
            }

            if (Schema::hasColumn('company_bot_settings', 'ai_provider')) {
                $table->dropColumn('ai_provider');
            }

            if (Schema::hasColumn('company_bot_settings', 'ai_chatbot_auto_reply_enabled')) {
                $table->dropColumn('ai_chatbot_auto_reply_enabled');
            }

            if (Schema::hasColumn('company_bot_settings', 'ai_internal_chat_enabled')) {
                $table->dropColumn('ai_internal_chat_enabled');
            }

            if (Schema::hasColumn('company_bot_settings', 'ai_enabled')) {
                $table->dropColumn('ai_enabled');
            }
        });
    }
};
