<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['company_id', 'status'], 'conversations_company_id_status_index');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->index(['conversation_id', 'direction'], 'messages_conversation_id_direction_index');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_company_id_status_index');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_conversation_id_direction_index');
        });
    }
};
