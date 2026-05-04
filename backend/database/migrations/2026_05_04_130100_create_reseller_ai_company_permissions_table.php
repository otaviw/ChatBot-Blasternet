<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reseller_ai_company_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->boolean('ai_chatbot_allowed')->default(false);
            $table->foreignId('allowed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('allowed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['reseller_id', 'company_id'], 'reseller_ai_company_permissions_unique');
            $table->index(['company_id', 'ai_chatbot_allowed'], 'reseller_ai_company_permissions_company_allowed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reseller_ai_company_permissions');
    }
};
