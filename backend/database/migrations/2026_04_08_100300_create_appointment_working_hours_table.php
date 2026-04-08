<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_working_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_profile_id')->constrained('appointment_staff_profiles')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'staff_profile_id', 'day_of_week', 'is_active'], 'appointment_hours_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_working_hours');
    }
};

