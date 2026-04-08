<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('appointment_services')->nullOnDelete();
            $table->foreignId('staff_profile_id')->constrained('appointment_staff_profiles')->cascadeOnDelete();
            $table->string('customer_name', 191)->nullable();
            $table->string('customer_phone', 40);
            $table->string('customer_email', 191)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('effective_start_at');
            $table->dateTime('effective_end_at');
            $table->unsignedSmallInteger('service_duration_minutes');
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0);
            $table->string('status', 24)->default('pending');
            $table->string('source', 24)->default('whatsapp');
            $table->text('notes')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancelled_reason', 191)->nullable();
            $table->foreignId('rescheduled_from_appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'staff_profile_id', 'starts_at'], 'appointments_staff_start_idx');
            $table->index(
                ['company_id', 'staff_profile_id', 'status', 'effective_start_at', 'effective_end_at'],
                'appointments_overlap_lookup_idx'
            );
            $table->index(['company_id', 'customer_phone', 'starts_at'], 'appointments_customer_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};

