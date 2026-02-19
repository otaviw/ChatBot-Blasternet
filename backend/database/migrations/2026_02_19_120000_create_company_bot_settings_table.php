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
        Schema::create('company_bot_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->string('timezone')->default('America/Sao_Paulo');
            $table->text('welcome_message')->nullable();
            $table->text('fallback_message')->nullable();
            $table->text('out_of_hours_message')->nullable();
            $table->json('business_hours')->nullable();
            $table->json('keyword_replies')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_bot_settings');
    }
};

