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
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')
                  ->constrained('chat_conversations')
                  ->cascadeOnDelete();
            $table->foreignId('sender_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->enum('type', ['text', 'image', 'file'])->default('text');
            $table->text('content')->nullable();
            $table->json('metadata')->nullable(); 
            $table->softDeletes();   
            $table->timestamp('edited_at')->nullable(); 
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
