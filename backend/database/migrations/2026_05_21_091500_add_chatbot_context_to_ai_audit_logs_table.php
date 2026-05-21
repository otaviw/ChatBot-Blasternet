<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_audit_logs')) {
            return;
        }

        Schema::table('ai_audit_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_audit_logs', 'source')) {
                $table->string('source', 40)->nullable()->after('action')->index();
            }

            if (! Schema::hasColumn('ai_audit_logs', 'inbox_conversation_id')) {
                $table->foreignId('inbox_conversation_id')
                    ->nullable()
                    ->after('conversation_id')
                    ->constrained('conversations')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('ai_audit_logs', 'message_id')) {
                $table->foreignId('message_id')
                    ->nullable()
                    ->after('inbox_conversation_id')
                    ->constrained('messages')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('ai_audit_logs', 'decision_log_id')) {
                $table->foreignId('decision_log_id')
                    ->nullable()
                    ->after('message_id')
                    ->constrained('ai_chatbot_decision_logs')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('ai_audit_logs', 'contact_phone_hash')) {
                $table->string('contact_phone_hash', 64)->nullable()->after('decision_log_id')->index();
            }

            if (! Schema::hasColumn('ai_audit_logs', 'contact_name')) {
                $table->string('contact_name', 160)->nullable()->after('contact_phone_hash');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_audit_logs')) {
            return;
        }

        Schema::table('ai_audit_logs', function (Blueprint $table): void {
            foreach (['decision_log_id', 'message_id', 'inbox_conversation_id'] as $column) {
                if (Schema::hasColumn('ai_audit_logs', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            foreach (['contact_name', 'contact_phone_hash', 'source'] as $column) {
                if (Schema::hasColumn('ai_audit_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
