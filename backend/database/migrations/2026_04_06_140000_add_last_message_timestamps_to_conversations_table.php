<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('last_user_message_at')->nullable()->after('bot_last_interaction_at');
            $table->timestamp('last_business_message_at')->nullable()->after('last_user_message_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['last_user_message_at', 'last_business_message_at']);
        });
    }
};
