<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('chat_conversations')) {
            Schema::table('chat_conversations', function (Blueprint $table) {
                if (! Schema::hasColumn('chat_conversations', 'name')) {
                    $table->string('name', 120)->nullable()->after('type');
                }

                if (! Schema::hasColumn('chat_conversations', 'deleted_at')) {
                    $table->timestamp('deleted_at')->nullable()->after('updated_at');
                }
            });
        }

        if (Schema::hasTable('chat_participants')) {
            Schema::table('chat_participants', function (Blueprint $table) {
                if (! Schema::hasColumn('chat_participants', 'is_admin')) {
                    $table->boolean('is_admin')->default(false)->after('last_read_at');
                }

                if (! Schema::hasColumn('chat_participants', 'hidden_at')) {
                    $table->timestamp('hidden_at')->nullable()->after('is_admin');
                }

                if (! Schema::hasColumn('chat_participants', 'left_at')) {
                    $table->timestamp('left_at')->nullable()->after('hidden_at');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('chat_participants')) {
            Schema::table('chat_participants', function (Blueprint $table) {
                if (Schema::hasColumn('chat_participants', 'left_at')) {
                    $table->dropColumn('left_at');
                }

                if (Schema::hasColumn('chat_participants', 'hidden_at')) {
                    $table->dropColumn('hidden_at');
                }

                if (Schema::hasColumn('chat_participants', 'is_admin')) {
                    $table->dropColumn('is_admin');
                }
            });
        }

        if (Schema::hasTable('chat_conversations')) {
            Schema::table('chat_conversations', function (Blueprint $table) {
                if (Schema::hasColumn('chat_conversations', 'deleted_at')) {
                    $table->dropColumn('deleted_at');
                }

                if (Schema::hasColumn('chat_conversations', 'name')) {
                    $table->dropColumn('name');
                }
            });
        }
    }
};
