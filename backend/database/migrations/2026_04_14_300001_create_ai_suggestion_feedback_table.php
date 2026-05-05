<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_suggestion_feedback', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('suggestion_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('helpful');
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['suggestion_id', 'user_id']); // one feedback per agent per suggestion
            $table->index('suggestion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_suggestion_feedback');
    }
};
