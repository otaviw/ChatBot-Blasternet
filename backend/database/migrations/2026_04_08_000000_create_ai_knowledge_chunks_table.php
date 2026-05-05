<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_knowledge_chunks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ai_knowledge_item_id')
                ->constrained('ai_company_knowledge')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('company_id');
            $table->string('title', 190);

            $table->text('chunk_content');
            $table->unsignedSmallInteger('chunk_index')->default(0);

            $table->longText('embedding')->nullable();
            $table->string('embedding_model', 120)->nullable();

            $table->timestamps();

            $table->index('company_id');
            $table->index(['ai_knowledge_item_id', 'chunk_index']);
            $table->index(['company_id', 'embedding_model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_chunks');
    }
};
