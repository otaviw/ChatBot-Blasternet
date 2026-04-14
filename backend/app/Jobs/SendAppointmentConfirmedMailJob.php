<?php

namespace App\Jobs;

use App\Mail\AppointmentConfirmedMail;
use App\Models\Appointment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendAppointmentConfirmedMailJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly int $appointmentId
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $appointment = Appointment::query()
            ->with(['service', 'staffProfile.user'])
            ->find($this->appointmentId);

        if (! $appointment || ! $appointment->customer_email) {
            return;
        }

        $timezone = $appointment->company?->botSetting?->timezone ?? 'America/Sao_Paulo';

        Mail::to($appointment->customer_email)
            ->send(new AppointmentConfirmedMail($appointment, $timezone));
    }
}
