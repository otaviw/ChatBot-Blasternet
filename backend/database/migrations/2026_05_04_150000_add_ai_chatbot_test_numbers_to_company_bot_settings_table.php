<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_bot_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('company_bot_settings', 'ai_chatbot_test_numbers')) {
                $table->json('ai_chatbot_test_numbers')
                    ->nullable()
                    ->after('ai_chatbot_sandbox_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_bot_settings', function (Blueprint $table) {
            if (Schema::hasColumn('company_bot_settings', 'ai_chatbot_test_numbers')) {
                $table->dropColumn('ai_chatbot_test_numbers');
            }
        });
    }
};

