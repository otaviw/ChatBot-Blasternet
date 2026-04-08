<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('display_name', 120)->nullable();
            $table->boolean('is_bookable')->default(true);
            $table->unsignedSmallInteger('slot_interval_minutes')->nullable();
            $table->unsignedInteger('booking_min_notice_minutes')->nullable();
            $table->unsignedInteger('booking_max_advance_days')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
            $table->index(['company_id', 'is_bookable']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_staff_profiles');
    }
};

