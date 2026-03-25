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
            if (! Schema::hasColumn('company_bot_settings', 'ai_chatbot_enabled')) {
                $table->boolean('ai_chatbot_enabled')->default(false)->after('ai_internal_chat_enabled');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_persona')) {
                $table->text('ai_persona')->nullable()->after('ai_system_prompt');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_tone')) {
                $table->string('ai_tone', 80)->nullable()->after('ai_persona');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_language')) {
                $table->string('ai_language', 40)->nullable()->after('ai_tone');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_formality')) {
                $table->string('ai_formality', 40)->nullable()->after('ai_language');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_max_context_messages')) {
                $table->unsignedInteger('ai_max_context_messages')->default(10)->after('ai_formality');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_monthly_limit')) {
                $table->unsignedInteger('ai_monthly_limit')->nullable()->after('ai_max_context_messages');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_usage_count')) {
                $table->unsignedInteger('ai_usage_count')->default(0)->after('ai_monthly_limit');
            }

            if (! Schema::hasColumn('company_bot_settings', 'ai_chatbot_mode')) {
                $table->string('ai_chatbot_mode', 40)->default('disabled')->after('ai_usage_count');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_bot_settings', function (Blueprint $table) {
            if (Schema::hasColumn('company_bot_settings', 'ai_chatbot_mode')) {
                $table->dropColumn('ai_chatbot_mode');
            }

            if (Schema::hasColumn('company_bot_settings', 'ai_usage_count')) {
                $table->dropColumn('ai_usage_count');
            }

            if (Schema::hasColumn('company_bot_settings', 'ai_monthly_limit')) {
                $table->dropColumn('ai_monthly_limit');
            }

            if (Schema::hasColumn('company_bot_settings', 'ai_max_context_messages')) {
                $table->dropColumn('ai_max_context_messages');
            }

            if (Schema::hasColumn('company_bot_settings', 'ai_formality')) {
                $table->dropColumn('ai_formality');
            }

            if (Schema::hasColumn('company_bot_settings', 'ai_language')) {
                $table->dropColumn('ai_language');
            }

            if (Schema::hasColumn('company_bot_settings', 'ai_tone')) {
                $table->dropColumn('ai_tone');
            }

            if (Schema::hasColumn('company_bot_settings', 'ai_persona')) {
                $table->dropColumn('ai_persona');
            }

            if (Schema::hasColumn('company_bot_settings', 'ai_chatbot_enabled')) {
                $table->dropColumn('ai_chatbot_enabled');
            }
        });
    }
};

