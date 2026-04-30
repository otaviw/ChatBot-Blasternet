<?php

declare(strict_types=1);


namespace App\Observers;

use App\Models\Appointment;
use App\Services\RealtimePublisher;
use App\Support\RealtimeEvents;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class AppointmentObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private RealtimePublisher $publisher,
    ) {}

    public function created(Appointment $appointment): void
    {
        $this->publisher->publish(
            RealtimeEvents::APPOINTMENT_CREATED,
            ["company:{$appointment->company_id}"],
            $this->payload($appointment),
        );
    }

    public function updated(Appointment $appointment): void
    {
        $this->publisher->publish(
            RealtimeEvents::APPOINTMENT_UPDATED,
            ["company:{$appointment->company_id}"],
            $this->payload($appointment),
        );
    }

    public function deleted(Appointment $appointment): void
    {
        $this->publisher->publish(
            RealtimeEvents::APPOINTMENT_UPDATED,
            ["company:{$appointment->company_id}"],
            [
                'appointmentId' => (int) $appointment->id,
                'companyId'     => (int) $appointment->company_id,
                'deleted'       => true,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Appointment $appointment): array
    {
        return [
            'appointmentId'    => (int) $appointment->id,
            'companyId'        => (int) $appointment->company_id,
            'staffProfileId'   => (int) $appointment->staff_profile_id,
            'status'           => (string) $appointment->status,
            'source'           => (string) $appointment->source,
            'customerName'     => (string) ($appointment->customer_name ?? ''),
            'customerPhone'    => (string) ($appointment->customer_phone ?? ''),
            'startsAt'         => $appointment->starts_at?->toIso8601String(),
            'endsAt'           => $appointment->ends_at?->toIso8601String(),
        ];
    }
}
