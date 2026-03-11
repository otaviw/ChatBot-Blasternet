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
        Schema::create('message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('whatsapp_message_id', 191)->nullable()->index();
            $table->string('reactor_phone', 32)->index();
            $table->string('emoji', 64)->nullable();
            $table->dateTime('reacted_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'reactor_phone']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_reactions');
    }
};
