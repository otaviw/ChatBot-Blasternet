<?php

use App\Support\Database\ForwardOnlyMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends ForwardOnlyMigration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_prompt_histories')) {
            return;
        }

        Schema::create('ai_prompt_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('ai_conversations')->nullOnDelete();
            $table->string('prompt_key', 120);
            $table->string('prompt_version', 40)->nullable();
            $table->string('prompt_environment', 20);
            $table->string('provider_requested', 60)->nullable();
            $table->string('provider_resolved', 60)->nullable();
            $table->boolean('fallback_used')->default(false);
            $table->string('system_prompt_hash', 64);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'prompt_key', 'created_at'], 'ai_prompt_hist_company_key_created_idx');
            $table->index(['prompt_environment', 'created_at'], 'ai_prompt_hist_env_created_idx');
            $table->index(['provider_resolved', 'created_at'], 'ai_prompt_hist_provider_created_idx');
        });
    }

    public function down(): void
    {
        $this->forwardOnlyDown();
    }
};

