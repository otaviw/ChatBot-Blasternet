<?php

declare(strict_types=1);


namespace App\Support\Appointments;

use App\Models\Appointment;
use App\Models\AppointmentService;
use App\Models\AppointmentSetting;
use App\Models\AppointmentStaffProfile;
use App\Models\AppointmentTimeOff;
use App\Models\AppointmentWorkingHour;
use App\Models\User;

class AppointmentSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serializeSettings(AppointmentSetting $settings): array
    {
        return [
            'id'                              => (int) $settings->id,
            'company_id'                      => (int) $settings->company_id,
            'timezone'                        => (string) $settings->timezone,
            'slot_interval_minutes'           => (int) $settings->slot_interval_minutes,
            'booking_min_notice_minutes'      => (int) $settings->booking_min_notice_minutes,
            'booking_max_advance_days'        => (int) $settings->booking_max_advance_days,
            'cancellation_min_notice_minutes' => (int) $settings->cancellation_min_notice_minutes,
            'reschedule_min_notice_minutes'   => (int) $settings->reschedule_min_notice_minutes,
            'allow_customer_choose_staff'     => (bool) $settings->allow_customer_choose_staff,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeService(AppointmentService $service): array
    {
        return [
            'id'                  => (int) $service->id,
            'company_id'          => (int) $service->company_id,
            'name'                => (string) $service->name,
            'description'         => $service->description,
            'duration_minutes'    => (int) $service->duration_minutes,
            'buffer_before_minutes' => (int) $service->buffer_before_minutes,
            'buffer_after_minutes'  => (int) $service->buffer_after_minutes,
            'max_bookings_per_slot' => (int) $service->max_bookings_per_slot,
            'is_active'           => (bool) $service->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeStaffProfile(AppointmentStaffProfile $profile, ?User $user): array
    {
        return [
            'id'                          => (int) $profile->id,
            'company_id'                  => (int) $profile->company_id,
            'user_id'                     => (int) $profile->user_id,
            'user_name'                   => (string) ($user?->name ?? ''),
            'user_email'                  => (string) ($user?->email ?? ''),
            'display_name'                => $profile->display_name,
            'is_bookable'                 => (bool) $profile->is_bookable,
            'slot_interval_minutes'       => $profile->slot_interval_minutes !== null ? (int) $profile->slot_interval_minutes : null,
            'booking_min_notice_minutes'  => $profile->booking_min_notice_minutes !== null ? (int) $profile->booking_min_notice_minutes : null,
            'booking_max_advance_days'    => $profile->booking_max_advance_days !== null ? (int) $profile->booking_max_advance_days : null,
            'working_hours'               => $profile->workingHours
                ->map(fn(AppointmentWorkingHour $hour) => [
                    'id'                    => (int) $hour->id,
                    'day_of_week'           => (int) $hour->day_of_week,
                    'start_time'            => mb_substr((string) $hour->start_time, 0, 5),
                    'break_start_time'      => $hour->break_start_time
                        ? mb_substr((string) $hour->break_start_time, 0, 5)
                        : null,
                    'break_end_time'        => $hour->break_end_time
                        ? mb_substr((string) $hour->break_end_time, 0, 5)
                        : null,
                    'end_time'              => mb_substr((string) $hour->end_time, 0, 5),
                    'slot_interval_minutes' => $hour->slot_interval_minutes !== null
                        ? (int) $hour->slot_interval_minutes
                        : null,
                    'is_active'             => (bool) $hour->is_active,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeTimeOff(AppointmentTimeOff $timeOff): array
    {
        return [
            'id'                  => (int) $timeOff->id,
            'company_id'          => (int) $timeOff->company_id,
            'staff_profile_id'    => $timeOff->staff_profile_id ? (int) $timeOff->staff_profile_id : null,
            'staff_name'          => $timeOff->staffProfile?->display_name ?: $timeOff->staffProfile?->user?->name,
            'starts_at'           => $timeOff->starts_at->toIso8601String(),
            'ends_at'             => $timeOff->ends_at->toIso8601String(),
            'is_all_day'          => (bool) $timeOff->is_all_day,
            'reason'              => $timeOff->reason,
            'source'              => (string) $timeOff->source,
            'created_by_user_id'  => $timeOff->created_by_user_id ? (int) $timeOff->created_by_user_id : null,
            'created_by_name'     => $timeOff->createdBy?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeAppointment(Appointment $appointment): array
    {
        return [
            'id'                => (int) $appointment->id,
            'company_id'        => (int) $appointment->company_id,
            'service_id'        => $appointment->service_id ? (int) $appointment->service_id : null,
            'service_name'      => $appointment->service?->name,
            'staff_profile_id'  => (int) $appointment->staff_profile_id,
            'staff_name'        => $appointment->staffProfile?->display_name ?: $appointment->staffProfile?->user?->name,
            'customer_name'     => $appointment->customer_name,
            'customer_phone'    => (string) $appointment->customer_phone,
            'customer_email'    => $appointment->customer_email,
            'starts_at'         => $appointment->starts_at->toIso8601String(),
            'ends_at'           => $appointment->ends_at->toIso8601String(),
            'effective_start_at' => $appointment->effective_start_at->toIso8601String(),
            'effective_end_at'  => $appointment->effective_end_at->toIso8601String(),
            'status'            => (string) $appointment->status,
            'source'            => (string) $appointment->source,
            'notes'             => $appointment->notes,
            'created_at'        => $appointment->created_at->toIso8601String(),
        ];
    }
}
