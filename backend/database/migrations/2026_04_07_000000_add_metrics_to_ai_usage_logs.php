<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adiciona colunas de observabilidade à tabela ai_usage_logs:
     *
     *  provider         — nome do provedor de IA (ex.: ollama, anthropic)
     *  model            — modelo usado (ex.: llama3, claude-sonnet-4-6)
     *  feature          — funcionalidade que originou a chamada
     *                     (internal_chat | conversation_suggestion | chatbot)
     *  status           — resultado da chamada (ok | error)
     *  response_time_ms — latência total da chamada ao provedor (ms)
     *  error_type       — categoria normalizada do erro
     *                     (timeout | provider_unavailable | validation | unknown)
     */
    public function up(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->string('provider', 60)->nullable()->after('type');
            $table->string('model', 120)->nullable()->after('provider');
            $table->string('feature', 40)->nullable()->after('model');
            $table->string('status', 10)->default('ok')->after('feature');
            $table->unsignedInteger('response_time_ms')->nullable()->after('tokens_used');
            $table->string('error_type', 40)->nullable()->after('response_time_ms');

            $table->index(['company_id', 'feature', 'created_at'], 'ai_usage_logs_company_feature_created');
            $table->index(['company_id', 'provider', 'created_at'], 'ai_usage_logs_company_provider_created');
            $table->index(['company_id', 'status', 'created_at'], 'ai_usage_logs_company_status_created');
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->dropIndex('ai_usage_logs_company_feature_created');
            $table->dropIndex('ai_usage_logs_company_provider_created');
            $table->dropIndex('ai_usage_logs_company_status_created');

            $table->dropColumn(['provider', 'model', 'feature', 'status', 'response_time_ms', 'error_type']);
        });
    }
};
