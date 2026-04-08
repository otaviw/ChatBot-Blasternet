<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->unsignedSmallInteger('slot_interval_minutes')->default(15);
            $table->unsignedInteger('booking_min_notice_minutes')->default(120);
            $table->unsignedInteger('booking_max_advance_days')->default(30);
            $table->unsignedInteger('cancellation_min_notice_minutes')->default(120);
            $table->unsignedInteger('reschedule_min_notice_minutes')->default(120);
            $table->boolean('allow_customer_choose_staff')->default(true);
            $table->timestamps();

            $table->unique('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_settings');
    }
};

