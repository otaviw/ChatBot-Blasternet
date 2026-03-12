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
        if (! Schema::hasTable('chat_participants')) {
            return;
        }

        if (! Schema::hasColumn('chat_participants', 'user_id')) {
            Schema::table('chat_participants', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable();
            });
        }

        $legacyColumns = [
            'participant_id',
            'chat_user_id',
            'users_id',
            'usuario_id',
        ];

        foreach ($legacyColumns as $legacyColumn) {
            if (! Schema::hasColumn('chat_participants', $legacyColumn)) {
                continue;
            }

            DB::statement("
                UPDATE chat_participants
                SET user_id = {$legacyColumn}
                WHERE user_id IS NULL
            ");
        }

        try {
            DB::statement('CREATE INDEX chat_participants_user_id_index ON chat_participants (user_id)');
        } catch (\Throwable) {
        }

        try {
            DB::statement('CREATE UNIQUE INDEX chat_participants_conversation_user_unique ON chat_participants (conversation_id, user_id)');
        } catch (\Throwable) {
        }

        try {
            DB::statement('ALTER TABLE chat_participants ADD CONSTRAINT chat_participants_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        } catch (\Throwable) {
        }

        $nullCount = (int) DB::table('chat_participants')->whereNull('user_id')->count();
        if ($nullCount === 0) {
            try {
                DB::statement('ALTER TABLE chat_participants ALTER COLUMN user_id SET NOT NULL');
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Compatibility migration: no destructive rollback.
    }
};
