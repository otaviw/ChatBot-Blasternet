<?php

namespace App\Actions\Appointment;

use App\Exceptions\AppointmentBusinessException;
use App\Models\Appointment;
use App\Models\AppointmentEvent;
use App\Models\Company;
use App\Models\User;
use App\Support\AppointmentStatus;

class RescheduleAppointmentAction
{
    public function handle(
        Company $company,
        Appointment $appointment,
        User $actor,
        string $newStatus,
        ?string $reason
    ): Appointment {
        if ((int) $appointment->company_id !== (int) $company->id) {
            throw new AppointmentBusinessException('Agendamento não pertence a empresa.', 404);
        }

        if ($newStatus === AppointmentStatus::CANCELLED) {
            throw new AppointmentBusinessException('Fluxo de cancelamento deve usar CancelAppointmentAction.');
        }

        $oldStatus = (string) $appointment->status;
        if ($oldStatus === $newStatus) {
            return $appointment;
        }

        $appointment->status = $newStatus;
        $appointment->save();

        AppointmentEvent::create([
            'company_id' => (int) $company->id,
            'appointment_id' => (int) $appointment->id,
            'event_type' => 'status_changed',
            'performed_by_user_id' => $actor->id ? (int) $actor->id : null,
            'payload' => [
                'from' => $oldStatus,
                'to' => $newStatus,
                'reason' => $this->nullableTrim($reason),
                'channel' => 'dashboard',
            ],
        ]);

        return $appointment;
    }

    private function nullableTrim(?string $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}

