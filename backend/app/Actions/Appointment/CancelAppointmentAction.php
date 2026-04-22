<?php

namespace App\Actions\Appointment;

use App\Exceptions\AppointmentBusinessException;
use App\Models\Appointment;
use App\Models\AppointmentEvent;
use App\Models\AppointmentSetting;
use App\Models\Company;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppSendService;
use App\Support\AppointmentStatus;

class CancelAppointmentAction
{
    public function __construct(
        private readonly WhatsAppSendService $whatsAppSend
    ) {}

    public function handle(
        Company $company,
        Appointment $appointment,
        User $actor,
        ?string $reason,
        bool $notifyCustomer
    ): Appointment {
        if ((int) $appointment->company_id !== (int) $company->id) {
            throw new AppointmentBusinessException('Agendamento não pertence a empresa.', 404);
        }

        $oldStatus = (string) $appointment->status;
        if ($oldStatus === AppointmentStatus::CANCELLED) {
            return $appointment;
        }

        $normalizedReason = $this->nullableTrim($reason);

        $appointment->status = AppointmentStatus::CANCELLED;
        $appointment->cancelled_at = now();
        $appointment->cancelled_reason = $normalizedReason;
        $appointment->save();

        AppointmentEvent::create([
            'company_id' => (int) $company->id,
            'appointment_id' => (int) $appointment->id,
            'event_type' => 'status_changed',
            'performed_by_user_id' => $actor->id ? (int) $actor->id : null,
            'payload' => [
                'from' => $oldStatus,
                'to' => AppointmentStatus::CANCELLED,
                'reason' => $normalizedReason,
                'channel' => 'dashboard',
            ],
        ]);

        if ($notifyCustomer) {
            $settings = AppointmentSetting::query()->where('company_id', (int) $company->id)->first();
            $timezone = (string) ($settings?->timezone ?: 'America/Sao_Paulo');
            $startsAt = $appointment->starts_at->setTimezone($timezone);
            $dateStr = $startsAt->format('d/m/Y') ?? '';
            $timeStr = $startsAt->format('H:i') ?? '';
            $reasonLine = $normalizedReason ? "\nMotivo: {$normalizedReason}" : '';
            $text = "Olá! Informamos que seu agendamento do dia {$dateStr} às {$timeStr} foi cancelado pela nossa equipe.{$reasonLine}\nPor favor, entre em contato para reagendar se desejar.";
            $this->whatsAppSend->sendText($company, (string) $appointment->customer_phone, $text);
        }

        return $appointment;
    }

    private function nullableTrim(?string $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}

