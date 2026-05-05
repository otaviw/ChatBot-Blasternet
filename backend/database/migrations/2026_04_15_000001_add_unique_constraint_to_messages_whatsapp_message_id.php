<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Torna whatsapp_message_id globalmente único na tabela messages.
 *
 * Motivação: a Meta pode reenviar o mesmo evento de webhook múltiplas vezes
 * (retry automático, falha temporária, janela de verificação). Sem esta constraint,
 * o mesmo wamid poderia gerar mensagens duplicadas.
 *
 * NULL é permitido e repetível — mensagens outbound e simuladas não têm wamid.
 * Em MySQL/MariaDB e PostgreSQL, múltiplos NULLs em coluna UNIQUE são tratados
 * como valores distintos e não violam a constraint.
 *
 * PRÉ-REQUISITO: se houver linhas com wamids duplicados no banco (bug anterior),
 * esta migration falhará. Execute a query abaixo para verificar antes de rodar:
 *
 *   SELECT whatsapp_message_id, COUNT(*) as n
 *   FROM messages
 *   WHERE whatsapp_message_id IS NOT NULL
 *   GROUP BY whatsapp_message_id
 *   HAVING n > 1;
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicateCount = (int) DB::table('messages')
            ->select('whatsapp_message_id')
            ->whereNotNull('whatsapp_message_id')
            ->groupBy('whatsapp_message_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicateCount > 0) {
            throw new \RuntimeException(
                "Não é possível adicionar UNIQUE em messages.whatsapp_message_id: " .
                "{$duplicateCount} wamid(s) duplicado(s) encontrado(s). " .
                "Resolva manualmente antes de rodar esta migration."
            );
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_whatsapp_message_id_index');

            $table->unique('whatsapp_message_id', 'messages_whatsapp_message_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique('messages_whatsapp_message_id_unique');
            $table->index('whatsapp_message_id', 'messages_whatsapp_message_id_index');
        });
    }
};
