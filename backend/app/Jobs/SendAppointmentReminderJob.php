<?php

namespace App\Jobs;

use App\Mail\AppointmentReminderMail;
use App\Models\Appointment;
use App\Support\AppointmentStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class SendAppointmentReminderJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $now = Carbon::now();
        $windowEnd = $now->copy()->addHours(24);

        // Find confirmed appointments with email, not yet reminded, starting within the next 24h
        Appointment::query()
            ->with(['company.botSetting', 'service', 'staffProfile.user'])
            ->whereNotNull('customer_email')
            ->whereNull('reminder_sent_at')
            ->where('status', AppointmentStatus::CONFIRMED)
            ->whereBetween('starts_at', [$now, $windowEnd])
            ->chunkById(50, function ($appointments) {
                foreach ($appointments as $appointment) {
                    $timezone = $appointment->company?->botSetting?->timezone ?? 'America/Sao_Paulo';

                    Mail::to($appointment->customer_email)
                        ->send(new AppointmentReminderMail($appointment, $timezone));

                    $appointment->update(['reminder_sent_at' => now()]);
                }
            });
    }
}
