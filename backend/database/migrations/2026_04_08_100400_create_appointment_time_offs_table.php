<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_time_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_profile_id')->nullable()->constrained('appointment_staff_profiles')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('is_all_day')->default(false);
            $table->string('reason', 191)->nullable();
            $table->string('source', 32)->default('manual');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'staff_profile_id', 'starts_at', 'ends_at'], 'appointment_time_off_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_time_offs');
    }
};

