<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chatbot_decision_logs', function (Blueprint $table) {
            $table->string('channel', 40)->default('whatsapp')->after('user_id');
            $table->string('flow', 80)->nullable()->after('channel');
            $table->string('step', 120)->nullable()->after('flow');
            $table->foreignId('handoff_area_id')->nullable()->after('handoff_reason')->constrained('areas')->nullOnDelete();
            $table->string('handoff_area_name', 120)->nullable()->after('handoff_area_id');
            $table->string('handoff_type', 40)->nullable()->after('handoff_area_name');

            $table->index(['company_id', 'channel', 'created_at'], 'ai_chatbot_decision_logs_company_channel_created');
            $table->index(['company_id', 'flow', 'created_at'], 'ai_chatbot_decision_logs_company_flow_created');
            $table->index(['company_id', 'handoff_type', 'created_at'], 'ai_chatbot_decision_logs_company_handoff_created');
        });
    }

    public function down(): void
    {
        Schema::table('ai_chatbot_decision_logs', function (Blueprint $table) {
            $table->dropIndex('ai_chatbot_decision_logs_company_channel_created');
            $table->dropIndex('ai_chatbot_decision_logs_company_flow_created');
            $table->dropIndex('ai_chatbot_decision_logs_company_handoff_created');
            $table->dropConstrainedForeignId('handoff_area_id');
            $table->dropColumn([
                'channel',
                'flow',
                'step',
                'handoff_area_name',
                'handoff_type',
            ]);
        });
    }
};
