<?php

use App\Support\Database\ForwardOnlyMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends ForwardOnlyMigration
{
    public function up(): void
    {
        if (Schema::hasTable('product_events')) {
            return;
        }

        Schema::create('product_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('funnel', 64);
            $table->string('step', 64);
            $table->string('event_name', 120);
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['company_id', 'funnel', 'occurred_at'], 'product_events_company_funnel_occ_idx');
            $table->index(['company_id', 'event_name', 'occurred_at'], 'product_events_company_event_occ_idx');
            $table->index(['funnel', 'step', 'occurred_at'], 'product_events_funnel_step_occ_idx');
            $table->index(['user_id', 'occurred_at'], 'product_events_user_occ_idx');
        });
    }

    public function down(): void
    {
        $this->forwardOnlyDown();
    }
};
