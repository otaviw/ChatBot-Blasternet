<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->normalizeChatConversationsTable();
        $this->normalizeChatParticipantsTable();
        $this->normalizeChatMessagesTable();
        $this->normalizeChatAttachmentsTable();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
    }

    private function normalizeChatConversationsTable(): void
    {
        if (! Schema::hasTable('chat_conversations')) {
            Schema::create('chat_conversations', function (Blueprint $table) {
                $table->id();
                $table->string('type', 20)->default('direct');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('company_id')->nullable();
                $table->timestamps();
            });

            return;
        }

        Schema::table('chat_conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('chat_conversations', 'type')) {
                $table->string('type', 20)->default('direct');
            }
            if (! Schema::hasColumn('chat_conversations', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable();
            }
            if (! Schema::hasColumn('chat_conversations', 'company_id')) {
                $table->unsignedBigInteger('company_id')->nullable();
            }
            if (! Schema::hasColumn('chat_conversations', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn('chat_conversations', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        $legacyOwnerColumns = ['user_id', 'owner_id', 'creator_id'];
        foreach ($legacyOwnerColumns as $legacyColumn) {
            if (! Schema::hasColumn('chat_conversations', $legacyColumn)) {
                continue;
            }

            DB::statement("
                UPDATE chat_conversations
                SET created_by = {$legacyColumn}
                WHERE created_by IS NULL
            ");
        }

        DB::table('chat_conversations')
            ->whereNull('type')
            ->update(['type' => 'direct']);
    }

    private function normalizeChatParticipantsTable(): void
    {
        if (! Schema::hasTable('chat_participants')) {
            Schema::create('chat_participants', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('conversation_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamp('joined_at')->nullable();
                $table->timestamp('last_read_at')->nullable();
            });

            return;
        }

        Schema::table('chat_participants', function (Blueprint $table) {
            if (! Schema::hasColumn('chat_participants', 'conversation_id')) {
                $table->unsignedBigInteger('conversation_id')->nullable();
            }
            if (! Schema::hasColumn('chat_participants', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable();
            }
            if (! Schema::hasColumn('chat_participants', 'joined_at')) {
                $table->timestamp('joined_at')->nullable();
            }
            if (! Schema::hasColumn('chat_participants', 'last_read_at')) {
                $table->timestamp('last_read_at')->nullable();
            }
        });

        $legacyUserColumns = ['participant_id', 'chat_user_id', 'users_id', 'usuario_id'];
        foreach ($legacyUserColumns as $legacyColumn) {
            if (! Schema::hasColumn('chat_participants', $legacyColumn)) {
                continue;
            }

            DB::statement("
                UPDATE chat_participants
                SET user_id = {$legacyColumn}
                WHERE user_id IS NULL
            ");
        }

        $legacyConversationColumns = ['chat_conversation_id', 'conversationid', 'chat_id', 'conversa_id'];
        foreach ($legacyConversationColumns as $legacyColumn) {
            if (! Schema::hasColumn('chat_participants', $legacyColumn)) {
                continue;
            }

            DB::statement("
                UPDATE chat_participants
                SET conversation_id = {$legacyColumn}
                WHERE conversation_id IS NULL
            ");
        }

        if (Schema::hasColumn('chat_participants', 'created_at')) {
            DB::statement('
                UPDATE chat_participants
                SET joined_at = created_at
                WHERE joined_at IS NULL
            ');
        }
    }

    private function normalizeChatMessagesTable(): void
    {
        if (! Schema::hasTable('chat_messages')) {
            Schema::create('chat_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('conversation_id')->nullable();
                $table->unsignedBigInteger('sender_id')->nullable();
                $table->string('type', 20)->default('text');
                $table->text('content')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('edited_at')->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });

            return;
        }

        Schema::table('chat_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('chat_messages', 'conversation_id')) {
                $table->unsignedBigInteger('conversation_id')->nullable();
            }
            if (! Schema::hasColumn('chat_messages', 'sender_id')) {
                $table->unsignedBigInteger('sender_id')->nullable();
            }
            if (! Schema::hasColumn('chat_messages', 'type')) {
                $table->string('type', 20)->default('text');
            }
            if (! Schema::hasColumn('chat_messages', 'content')) {
                $table->text('content')->nullable();
            }
            if (! Schema::hasColumn('chat_messages', 'metadata')) {
                $table->json('metadata')->nullable();
            }
            if (! Schema::hasColumn('chat_messages', 'edited_at')) {
                $table->timestamp('edited_at')->nullable();
            }
            if (! Schema::hasColumn('chat_messages', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable();
            }
            if (! Schema::hasColumn('chat_messages', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn('chat_messages', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        if (Schema::hasColumn('chat_messages', 'text')) {
            DB::statement('
                UPDATE chat_messages
                SET content = text
                WHERE content IS NULL
            ');
        }

        $legacySenderColumns = ['user_id', 'created_by'];
        foreach ($legacySenderColumns as $legacyColumn) {
            if (! Schema::hasColumn('chat_messages', $legacyColumn)) {
                continue;
            }

            DB::statement("
                UPDATE chat_messages
                SET sender_id = {$legacyColumn}
                WHERE sender_id IS NULL
            ");
        }

        $legacyConversationColumns = ['chat_conversation_id', 'conversationid', 'chat_id', 'conversa_id'];
        foreach ($legacyConversationColumns as $legacyColumn) {
            if (! Schema::hasColumn('chat_messages', $legacyColumn)) {
                continue;
            }

            DB::statement("
                UPDATE chat_messages
                SET conversation_id = {$legacyColumn}
                WHERE conversation_id IS NULL
            ");
        }

        DB::table('chat_messages')
            ->whereNull('type')
            ->update(['type' => 'text']);
    }

    private function normalizeChatAttachmentsTable(): void
    {
        if (! Schema::hasTable('chat_attachments')) {
            Schema::create('chat_attachments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('message_id')->nullable();
                $table->string('disk_path')->nullable();
                $table->string('url')->nullable();
                $table->string('mime_type', 100)->nullable();
                $table->unsignedBigInteger('size_bytes')->nullable();
                $table->string('original_name')->nullable();
                $table->timestamps();
            });

            return;
        }

        Schema::table('chat_attachments', function (Blueprint $table) {
            if (! Schema::hasColumn('chat_attachments', 'message_id')) {
                $table->unsignedBigInteger('message_id')->nullable();
            }
            if (! Schema::hasColumn('chat_attachments', 'disk_path')) {
                $table->string('disk_path')->nullable();
            }
            if (! Schema::hasColumn('chat_attachments', 'url')) {
                $table->string('url')->nullable();
            }
            if (! Schema::hasColumn('chat_attachments', 'mime_type')) {
                $table->string('mime_type', 100)->nullable();
            }
            if (! Schema::hasColumn('chat_attachments', 'size_bytes')) {
                $table->unsignedBigInteger('size_bytes')->nullable();
            }
            if (! Schema::hasColumn('chat_attachments', 'original_name')) {
                $table->string('original_name')->nullable();
            }
            if (! Schema::hasColumn('chat_attachments', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn('chat_attachments', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });

        $legacyMessageColumns = ['chat_message_id', 'messageid'];
        foreach ($legacyMessageColumns as $legacyColumn) {
            if (! Schema::hasColumn('chat_attachments', $legacyColumn)) {
                continue;
            }

            DB::statement("
                UPDATE chat_attachments
                SET message_id = {$legacyColumn}
                WHERE message_id IS NULL
            ");
        }

        if (Schema::hasColumn('chat_attachments', 'path')) {
            DB::statement('
                UPDATE chat_attachments
                SET disk_path = path
                WHERE disk_path IS NULL
            ');
        }

        DB::statement('
            UPDATE chat_attachments
            SET url = COALESCE(url, disk_path, \'\')
        ');
    }
};

