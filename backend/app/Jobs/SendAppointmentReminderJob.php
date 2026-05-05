<?php

declare(strict_types=1);


namespace App\Jobs;

use App\Mail\AppointmentReminderMail;
use App\Models\Appointment;
use App\Support\AppointmentStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendAppointmentReminderJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * Intervalo entre tentativas (segundos).
     * Este é um job de batch pesado — retries espaçados evitam sobrecarga no SMTP
     * em caso de falha transitória do servidor de e-mail.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct()
    {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $now = Carbon::now();
        $windowEnd = $now->copy()->addHours(24);

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

    /**
     * Chamado após esgotar todas as tentativas.
     * Agendamentos sem reminder_sent_at perderão o lembrete neste ciclo —
     * o próximo ciclo agendado pode não mais encontrá-los dentro da janela de 24h.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('SendAppointmentReminderJob: falhou após todas as tentativas.', [
            'attempts'        => $this->tries,
            'exception_class' => $exception !== null ? get_class($exception) : null,
            'exception'       => $exception?->getMessage(),
        ]);
    }
}
