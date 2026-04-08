<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\AppointmentSetting;
use App\Models\AppointmentStaffProfile;
use App\Models\AppointmentTimeOff;
use App\Models\AppointmentWorkingHour;
use App\Models\Company;
use App\Models\User;
use App\Services\Appointments\AppointmentAvailabilityService;
use App\Services\Appointments\AppointmentBookingService;
use App\Support\AppointmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AppointmentAvailabilityAndBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_available_slots_respects_working_hours_blockings_and_existing_bookings(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-19 08:00:00', 'America/Sao_Paulo'));

        $company = Company::create(['name' => 'Empresa Agenda']);
        AppointmentSetting::create([
            'company_id' => $company->id,
            'timezone' => 'America/Sao_Paulo',
            'slot_interval_minutes' => 30,
            'booking_min_notice_minutes' => 0,
            'booking_max_advance_days' => 60,
            'cancellation_min_notice_minutes' => 120,
            'reschedule_min_notice_minutes' => 120,
            'allow_customer_choose_staff' => true,
        ]);

        $service = AppointmentService::create([
            'company_id' => $company->id,
            'name' => 'Consulta',
            'duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 15,
            'max_bookings_per_slot' => 1,
            'is_active' => true,
        ]);

        $user = User::create([
            'name' => 'Atendente 01',
            'email' => 'atendente01@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $staffProfile = AppointmentStaffProfile::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'is_bookable' => true,
            'slot_interval_minutes' => 30,
            'booking_min_notice_minutes' => 0,
            'booking_max_advance_days' => 60,
        ]);

        AppointmentWorkingHour::create([
            'company_id' => $company->id,
            'staff_profile_id' => $staffProfile->id,
            'day_of_week' => 3,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'is_active' => true,
        ]);

        $existingStart = CarbonImmutable::parse('2026-05-20 09:30:00', 'America/Sao_Paulo')->setTimezone('UTC');
        $existingEnd = CarbonImmutable::parse('2026-05-20 10:00:00', 'America/Sao_Paulo')->setTimezone('UTC');

        Appointment::create([
            'company_id' => $company->id,
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'customer_phone' => '5511999999999',
            'starts_at' => $existingStart,
            'ends_at' => $existingEnd,
            'effective_start_at' => $existingStart,
            'effective_end_at' => $existingEnd->addMinutes(15),
            'service_duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 15,
            'status' => AppointmentStatus::CONFIRMED,
            'source' => 'whatsapp',
        ]);

        AppointmentTimeOff::create([
            'company_id' => $company->id,
            'staff_profile_id' => $staffProfile->id,
            'starts_at' => CarbonImmutable::parse('2026-05-20 11:00:00', 'America/Sao_Paulo')->setTimezone('UTC'),
            'ends_at' => CarbonImmutable::parse('2026-05-20 11:30:00', 'America/Sao_Paulo')->setTimezone('UTC'),
            'is_all_day' => false,
            'source' => 'manual',
        ]);

        $availabilityService = app(AppointmentAvailabilityService::class);
        $result = $availabilityService->listAvailableSlots($company, $service->id, '2026-05-20', $staffProfile->id);

        $slots = collect($result['staff'][0]['slots'] ?? [])->pluck('starts_at_local')->all();

        $this->assertCount(2, $slots);
        $this->assertTrue(collect($slots)->contains(fn(string $value) => str_contains($value, 'T10:30:00')));
        $this->assertTrue(collect($slots)->contains(fn(string $value) => str_contains($value, 'T11:30:00')));
    }

    public function test_booking_service_creates_appointment_with_snapshot_and_event(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-19 08:00:00', 'America/Sao_Paulo'));

        $company = Company::create(['name' => 'Empresa Booking']);
        AppointmentSetting::create([
            'company_id' => $company->id,
            'timezone' => 'America/Sao_Paulo',
            'slot_interval_minutes' => 30,
            'booking_min_notice_minutes' => 0,
            'booking_max_advance_days' => 30,
            'cancellation_min_notice_minutes' => 120,
            'reschedule_min_notice_minutes' => 120,
            'allow_customer_choose_staff' => true,
        ]);

        $service = AppointmentService::create([
            'company_id' => $company->id,
            'name' => 'Avaliacao',
            'duration_minutes' => 45,
            'buffer_before_minutes' => 10,
            'buffer_after_minutes' => 5,
            'max_bookings_per_slot' => 1,
            'is_active' => true,
        ]);

        $user = User::create([
            'name' => 'Atendente 02',
            'email' => 'atendente02@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_COMPANY_ADMIN,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $staffProfile = AppointmentStaffProfile::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'is_bookable' => true,
            'slot_interval_minutes' => 30,
            'booking_min_notice_minutes' => 0,
            'booking_max_advance_days' => 30,
        ]);

        AppointmentWorkingHour::create([
            'company_id' => $company->id,
            'staff_profile_id' => $staffProfile->id,
            'day_of_week' => 3,
            'start_time' => '09:00:00',
            'end_time' => '13:00:00',
            'is_active' => true,
        ]);

        $bookingService = app(AppointmentBookingService::class);
        $appointment = $bookingService->createAppointment($company, [
            'service_id' => (int) $service->id,
            'staff_profile_id' => (int) $staffProfile->id,
            'starts_at' => '2026-05-20 09:30:00',
            'customer_name' => 'Cliente Teste',
            'customer_phone' => '5511988887777',
            'source' => 'dashboard',
            'meta' => ['origin' => 'manual'],
        ], $user);

        $this->assertSame(AppointmentStatus::PENDING, $appointment->status);
        $this->assertSame(45, (int) $appointment->service_duration_minutes);
        $this->assertSame(10, (int) $appointment->buffer_before_minutes);
        $this->assertSame(5, (int) $appointment->buffer_after_minutes);
        $this->assertSame('dashboard', $appointment->source);
        $this->assertDatabaseHas('appointment_events', [
            'appointment_id' => $appointment->id,
            'event_type' => 'created',
            'performed_by_user_id' => $user->id,
        ]);
    }

    public function test_booking_service_blocks_conflicting_appointment(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-19 08:00:00', 'America/Sao_Paulo'));

        $company = Company::create(['name' => 'Empresa Conflito']);
        AppointmentSetting::create([
            'company_id' => $company->id,
            'timezone' => 'America/Sao_Paulo',
            'slot_interval_minutes' => 30,
            'booking_min_notice_minutes' => 0,
            'booking_max_advance_days' => 30,
            'cancellation_min_notice_minutes' => 120,
            'reschedule_min_notice_minutes' => 120,
            'allow_customer_choose_staff' => true,
        ]);

        $service = AppointmentService::create([
            'company_id' => $company->id,
            'name' => 'Consulta',
            'duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'max_bookings_per_slot' => 1,
            'is_active' => true,
        ]);

        $user = User::create([
            'name' => 'Atendente 03',
            'email' => 'atendente03@test.local',
            'password' => 'secret123',
            'role' => User::ROLE_AGENT,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $staffProfile = AppointmentStaffProfile::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'is_bookable' => true,
            'slot_interval_minutes' => 30,
            'booking_min_notice_minutes' => 0,
            'booking_max_advance_days' => 30,
        ]);

        AppointmentWorkingHour::create([
            'company_id' => $company->id,
            'staff_profile_id' => $staffProfile->id,
            'day_of_week' => 3,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'is_active' => true,
        ]);

        $existingStart = CarbonImmutable::parse('2026-05-20 09:30:00', 'America/Sao_Paulo')->setTimezone('UTC');
        $existingEnd = CarbonImmutable::parse('2026-05-20 10:00:00', 'America/Sao_Paulo')->setTimezone('UTC');

        Appointment::create([
            'company_id' => $company->id,
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'customer_phone' => '5511911111111',
            'starts_at' => $existingStart,
            'ends_at' => $existingEnd,
            'effective_start_at' => $existingStart,
            'effective_end_at' => $existingEnd,
            'service_duration_minutes' => 30,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'status' => AppointmentStatus::CONFIRMED,
            'source' => 'dashboard',
        ]);

        $bookingService = app(AppointmentBookingService::class);

        $this->expectException(ValidationException::class);
        $bookingService->createAppointment($company, [
            'service_id' => (int) $service->id,
            'staff_profile_id' => (int) $staffProfile->id,
            'starts_at' => '2026-05-20 09:30:00',
            'customer_name' => 'Outro Cliente',
            'customer_phone' => '5511977776666',
            'source' => 'whatsapp',
        ], $user);
    }
}
