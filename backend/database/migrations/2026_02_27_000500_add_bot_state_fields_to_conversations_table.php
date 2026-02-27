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
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('bot_flow', 50)->nullable()->after('closed_at');
            $table->string('bot_step', 80)->nullable()->after('bot_flow');
            $table->json('bot_context')->nullable()->after('bot_step');
            $table->timestamp('bot_last_interaction_at')->nullable()->after('bot_context');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn([
                'bot_flow',
                'bot_step',
                'bot_context',
                'bot_last_interaction_at',
            ]);
        });
    }
};

