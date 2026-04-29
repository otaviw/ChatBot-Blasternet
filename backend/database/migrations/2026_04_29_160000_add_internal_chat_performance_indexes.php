<?php

use App\Support\Database\ForwardOnlyMigration;

return new class extends ForwardOnlyMigration
{
    public function up(): void
    {
        $this->addIndexIfMissing(
            'chat_messages',
            ['conversation_id', 'created_at'],
            'chat_messages_conversation_created_at_idx'
        );

        $this->addIndexIfMissing(
            'chat_participants',
            ['user_id', 'left_at', 'hidden_at', 'conversation_id'],
            'chat_participants_visibility_lookup_idx'
        );

        $this->addIndexIfMissing(
            'chat_conversations',
            ['deleted_at'],
            'chat_conversations_deleted_at_idx'
        );
    }

    public function down(): void
    {
        $this->forwardOnlyDown();
    }
};
