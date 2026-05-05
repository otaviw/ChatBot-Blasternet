<?php

use App\Support\Database\ForwardOnlyMigration;

return new class extends ForwardOnlyMigration
{
    public function up(): void
    {
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

        $this->addIndexIfMissing(
            'conversations',
            ['company_id', 'created_at', 'id'],
            'conversations_company_created_id_idx'
        );

        $this->addIndexIfMissing(
            'messages',
            ['conversation_id', 'id'],
            'messages_conversation_id_id_idx'
        );

        $this->addIndexIfMissing(
            'chat_messages',
            ['conversation_id', 'id'],
            'chat_messages_conversation_id_id_idx'
        );

        $this->addIndexIfMissing(
            'chat_conversations',
            ['type', 'deleted_at', 'id'],
            'chat_conversations_type_deleted_id_idx'
        );

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
