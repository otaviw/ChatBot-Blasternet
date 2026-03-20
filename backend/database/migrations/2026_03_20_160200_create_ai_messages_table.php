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
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 20);
            $table->longText('content')->nullable();
            $table->string('provider', 60)->nullable();
            $table->string('model', 120)->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->json('raw_payload')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['ai_conversation_id', 'id']);
            $table->index('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
