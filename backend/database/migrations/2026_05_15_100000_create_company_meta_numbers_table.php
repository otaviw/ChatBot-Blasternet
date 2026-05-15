<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_meta_numbers')) {
            return;
        }

        Schema::create('company_meta_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('phone_number', 32);
            $table->string('display_name', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_primary')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'phone_number'], 'company_meta_numbers_company_phone_unique');
            $table->index(['company_id', 'is_active'], 'company_meta_numbers_company_active_idx');
            $table->index(['company_id', 'is_primary'], 'company_meta_numbers_company_primary_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_meta_numbers');
    }
};

