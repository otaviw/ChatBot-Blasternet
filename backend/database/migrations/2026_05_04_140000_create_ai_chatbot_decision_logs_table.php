<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chatbot_decision_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 20)->default('off');
            $table->json('gate_result')->nullable();
            $table->string('intent', 120)->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('action', 120)->nullable();
            $table->string('handoff_reason', 255)->nullable();
            $table->boolean('used_knowledge')->default(false);
            $table->json('knowledge_refs')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('tokens_used')->nullable();
            $table->string('provider', 60)->nullable();
            $table->string('model', 120)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'mode', 'created_at'], 'ai_chatbot_decision_logs_company_mode_created');
            $table->index(['conversation_id', 'created_at'], 'ai_chatbot_decision_logs_conversation_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chatbot_decision_logs');
    }
};
