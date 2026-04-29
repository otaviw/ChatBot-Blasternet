<?php

use App\Support\Database\ForwardOnlyMigration;

return new class extends ForwardOnlyMigration
{
    public function up(): void
    {
        // LOGIN / USUARIOS
        // Listagens e filtros frequentes por empresa + perfil (com ordenacao por nome).
        $this->addIndexIfMissing(
            'users',
            ['company_id', 'role', 'name'],
            'users_company_role_name_idx'
        );
        $this->addIndexIfMissing(
            'users',
            ['role', 'is_active', 'name'],
            'users_role_active_name_idx'
        );

        // BUSCAS (inbox): company + periodo + ordenacao por recencia.
        $this->addIndexIfMissing(
            'conversations',
            ['company_id', 'created_at', 'id'],
            'conversations_company_created_id_idx'
        );

        // MENSAGENS (inbox/search): ultima por conversa e paginacao por id.
        $this->addIndexIfMissing(
            'messages',
            ['conversation_id', 'id'],
            'messages_conversation_id_id_idx'
        );

        // CHATS internos: paginacao/ordenacao por id dentro da conversa.
        $this->addIndexIfMissing(
            'chat_messages',
            ['conversation_id', 'id'],
            'chat_messages_conversation_id_id_idx'
        );

        // Localizacao de conversas diretas ativas.
        $this->addIndexIfMissing(
            'chat_conversations',
            ['type', 'deleted_at', 'id'],
            'chat_conversations_type_deleted_id_idx'
        );

        // TICKETS: listagem do solicitante e painel admin (filtros + recencia).
        $this->addIndexIfMissing(
            'support_tickets',
            ['requester_user_id', 'id'],
            'support_tickets_requester_id_id_idx'
        );
        $this->addIndexIfMissing(
            'support_tickets',
            ['company_id', 'status', 'id'],
            'support_tickets_company_status_id_idx'
        );

        // BUSCAS de contatos por empresa com ordenacao alfabetica.
        $this->addIndexIfMissing(
            'contacts',
            ['company_id', 'name', 'id'],
            'contacts_company_name_id_idx'
        );
    }

    public function down(): void
    {
        $this->forwardOnlyDown();
    }
};
