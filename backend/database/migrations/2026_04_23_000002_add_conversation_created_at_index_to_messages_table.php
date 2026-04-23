<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona índice composto (conversation_id, created_at) na tabela messages.
 *
 * Por que composto e não só conversation_id:
 *   A FK em conversation_id já cria um índice simples, mas queries que ordenam
 *   ou filtram por created_at dentro de uma conversa (ex: listar mensagens paginadas,
 *   buscar mensagens recentes) fazem filesort sem este índice composto.
 *
 * Os demais índices pedidos já existem:
 *   - messages.whatsapp_message_id → UNIQUE desde 2026_04_15_000001
 *   - contacts.phone               → index() + unique([company_id, phone]) desde 2026_04_17_000000
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index(
                ['conversation_id', 'created_at'],
                'messages_conversation_id_created_at_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_conversation_id_created_at_index');
        });
    }
};
