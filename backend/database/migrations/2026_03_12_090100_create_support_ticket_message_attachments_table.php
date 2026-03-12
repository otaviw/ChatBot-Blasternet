<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_message_id')
                ->constrained('support_ticket_messages')
                ->cascadeOnDelete();
            $table->string('storage_provider', 32)->default('public');
            $table->string('storage_key', 512);
            $table->string('url', 1024)->nullable();
            $table->string('mime_type', 64)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_message_attachments');
    }
};
