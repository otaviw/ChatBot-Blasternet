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
        Schema::table('messages', function (Blueprint $table) {
            if (! Schema::hasColumn('messages', 'whatsapp_message_id')) {
                $table->string('whatsapp_message_id', 191)
                    ->nullable()
                    ->after('meta')
                    ->index();
            }

            if (! Schema::hasColumn('messages', 'delivery_status')) {
                $table->string('delivery_status', 24)
                    ->default('pending')
                    ->after('whatsapp_message_id');
            }

            if (! Schema::hasColumn('messages', 'sent_at')) {
                $table->dateTime('sent_at')->nullable()->after('delivery_status');
            }

            if (! Schema::hasColumn('messages', 'delivered_at')) {
                $table->dateTime('delivered_at')->nullable()->after('sent_at');
            }

            if (! Schema::hasColumn('messages', 'read_at')) {
                $table->dateTime('read_at')->nullable()->after('delivered_at');
            }

            if (! Schema::hasColumn('messages', 'failed_at')) {
                $table->dateTime('failed_at')->nullable()->after('read_at');
            }

            if (! Schema::hasColumn('messages', 'status_error')) {
                $table->text('status_error')->nullable()->after('failed_at');
            }

            if (! Schema::hasColumn('messages', 'status_meta')) {
                $table->json('status_meta')->nullable()->after('status_error');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'status_meta')) {
                $table->dropColumn('status_meta');
            }

            if (Schema::hasColumn('messages', 'status_error')) {
                $table->dropColumn('status_error');
            }

            if (Schema::hasColumn('messages', 'failed_at')) {
                $table->dropColumn('failed_at');
            }

            if (Schema::hasColumn('messages', 'read_at')) {
                $table->dropColumn('read_at');
            }

            if (Schema::hasColumn('messages', 'delivered_at')) {
                $table->dropColumn('delivered_at');
            }

            if (Schema::hasColumn('messages', 'sent_at')) {
                $table->dropColumn('sent_at');
            }

            if (Schema::hasColumn('messages', 'delivery_status')) {
                $table->dropColumn('delivery_status');
            }

            if (Schema::hasColumn('messages', 'whatsapp_message_id')) {
                $table->dropColumn('whatsapp_message_id');
            }
        });
    }
};
