<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['company_id', 'status'], 'idx_conversations_company_status');
            $table->index(['company_id', 'last_user_message_at'], 'idx_conversations_company_last_msg');
        });

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->index(['ai_conversation_id', 'created_at'], 'idx_ai_messages_conversation_created');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('idx_conversations_company_status');
            $table->dropIndex('idx_conversations_company_last_msg');
        });

        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropIndex('idx_ai_messages_conversation_created');
        });
    }
};
