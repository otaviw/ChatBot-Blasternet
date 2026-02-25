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
        Schema::create('conversation_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->enum('from_assigned_type', ['user', 'area', 'bot', 'unassigned']);
            $table->unsignedBigInteger('from_assigned_id')->nullable();
            $table->enum('to_assigned_type', ['user', 'area', 'bot', 'unassigned']);
            $table->unsignedBigInteger('to_assigned_id')->nullable();
            $table->foreignId('transferred_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['company_id', 'created_at']);
            $table->index(['to_assigned_type', 'to_assigned_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_transfers');
    }
};
