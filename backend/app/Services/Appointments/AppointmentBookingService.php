<?php

declare(strict_types=1);


namespace App\Services\Appointments;

use App\Jobs\SendAppointmentConfirmedMailJob;
use App\Models\Appointment;
use App\Models\AppointmentEvent;
use App\Models\AppointmentService;
use App\Models\AppointmentStaffProfile;
use App\Models\Company;
use App\Models\User;
use App\Support\AppointmentSource;
use App\Support\AppointmentStatus;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentBookingService
{
    public function __construct(
        private AppointmentAvailabilityService $availabilityService
    ) {}

    /**
     * @param array{
     *   service_id:int,
     *   staff_profile_id:int,
     *   starts_at:string,
     *   customer_phone:string,
     *   customer_name?:string|null,
     *   customer_email?:string|null,
     *   notes?:string|null,
     *   source?:string|null,
     *   meta?:array<string,mixed>|null
     * } $payload
     */
    public function createAppointment(Company $company, array $payload, ?User $actor = null): Appointment
    {
        $serviceId = (int) ($payload['service_id'] ?? 0);
        $staffProfileId = (int) ($payload['staff_profile_id'] ?? 0);
        $startsAt = (string) ($payload['starts_at'] ?? '');
        $customerPhone = PhoneNumberNormalizer::normalizeBrazil((string) ($payload['customer_phone'] ?? ''));

        if ($serviceId <= 0) {
            throw ValidationException::withMessages([
                'service_id' => ['Serviço obrigatório para criar agendamento.'],
            ]);
        }

        if ($staffProfileId <= 0) {
            throw ValidationException::withMessages([
                'staff_profile_id' => ['Atendente obrigatório para criar agendamento.'],
            ]);
        }

        if ($startsAt === '') {
            throw ValidationException::withMessages([
                'starts_at' => ['Horario obrigatório para criar agendamento.'],
            ]);
        }

        if ($customerPhone === '') {
            throw ValidationException::withMessages([
                'customer_phone' => ['Telefone do cliente obrigatório para criar agendamento.'],
            ]);
        }

        return DB::transaction(function () use ($company, $payload, $actor, $serviceId, $staffProfileId, $startsAt, $customerPhone) {
            $service = AppointmentService::query()
                ->where('company_id', (int) $company->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->find($serviceId);

            if (! $service) {
                throw ValidationException::withMessages([
                'service_id' => ['Serviço não encontrado ou inativo para esta empresa.'],
                ]);
            }

            $staffProfile = AppointmentStaffProfile::query()
                ->where('company_id', (int) $company->id)
                ->where('is_bookable', true)
                ->lockForUpdate()
                ->find($staffProfileId);

            if (! $staffProfile) {
                throw ValidationException::withMessages([
                    'staff_profile_id' => ['Atendente não encontrado ou indisponivel para agendamento.'],
                ]);
            }

            $slot = $this->availabilityService->assertSlotIsAvailable(
                $company,
                $service,
                $staffProfile,
                $startsAt,
                true
            );

            $source = mb_strtolower(trim((string) ($payload['source'] ?? AppointmentSource::WHATSAPP)));
            if (! in_array($source, AppointmentSource::all(), true)) {
                $source = AppointmentSource::WHATSAPP;
            }

            $appointment = Appointment::create([
                'company_id' => (int) $company->id,
                'service_id' => (int) $service->id,
                'staff_profile_id' => (int) $staffProfile->id,
                'customer_name' => $this->nullableTrim($payload['customer_name'] ?? null),
                'customer_phone' => $customerPhone,
                'customer_email' => $this->nullableTrim($payload['customer_email'] ?? null),
                'starts_at' => $slot['starts_at_utc'],
                'ends_at' => $slot['ends_at_utc'],
                'effective_start_at' => $slot['effective_start_at_utc'],
                'effective_end_at' => $slot['effective_end_at_utc'],
                'service_duration_minutes' => (int) $service->duration_minutes,
                'buffer_before_minutes' => (int) $service->buffer_before_minutes,
                'buffer_after_minutes' => (int) $service->buffer_after_minutes,
                'status' => $source === AppointmentSource::WHATSAPP ? AppointmentStatus::CONFIRMED : AppointmentStatus::PENDING,
                'source' => $source,
                'notes' => $this->nullableTrim($payload['notes'] ?? null),
                'created_by_user_id' => $actor?->id ? (int) $actor->id : null,
                'meta' => $this->normalizeMeta($payload['meta'] ?? null),
            ]);

            AppointmentEvent::create([
                'company_id' => (int) $company->id,
                'appointment_id' => (int) $appointment->id,
                'event_type' => 'created',
                'performed_by_user_id' => $actor?->id ? (int) $actor->id : null,
                'payload' => [
                    'status' => $appointment->status,
                    'source' => $appointment->source,
                    'starts_at' => $appointment->starts_at->toIso8601String(),
                    'ends_at' => $appointment->ends_at->toIso8601String(),
                    'timezone' => (string) $slot['timezone'],
                ],
            ]);

            $fresh = $appointment->fresh(['service', 'staffProfile.user', 'events']);

            if ($appointment->customer_email && $appointment->status === AppointmentStatus::CONFIRMED) {
                SendAppointmentConfirmedMailJob::dispatch((int) $appointment->id);
            }

            return $fresh;
        });
    }

    /**
     * @param mixed $value
     */
    private function nullableTrim($value): ?string
    {
        $stringValue = trim((string) $value);

        return $stringValue === '' ? null : $stringValue;
    }

    /**
     * @param mixed $meta
     * @return array<string, mixed>|null
     */
    private function normalizeMeta($meta): ?array
    {
        if (! is_array($meta) || $meta === []) {
            return null;
        }

        return $meta;
    }
}
