<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Inbox queries filtram por company_id + status o tempo todo.
        // O FK em company_id já cria um índice simples, mas sem status o MySQL
        // faz filesort ao listar conversas abertas/em_progresso por empresa.
        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['company_id', 'status'], 'conversations_company_id_status_index');
        });

        // isFirstInboundMessage (InboundMessageService) faz:
        //   WHERE conversation_id = ? AND direction = 'in'
        // O índice composto (conversation_id, created_at) cobre ordenação, mas
        // não filtragem por direction — esta coluna fica fora do índice e força
        // um scan parcial de todas as mensagens da conversa.
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
