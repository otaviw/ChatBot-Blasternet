<?php

namespace App\Jobs;

use App\Mail\AppointmentConfirmedMail;
use App\Models\Appointment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendAppointmentConfirmedMailJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * Intervalo entre tentativas (segundos).
     * Erros transitórios de SMTP (conexão recusada, timeout) tendem a se resolver
     * em segundos a minutos. 30s e 2min dão margem sem deixar o cliente esperando
     * muito tempo pelo e-mail de confirmação.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

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

    /**
     * Chamado após esgotar todas as tentativas.
     * O agendamento não recebe confirmação — equipe deve ser notificada para
     * remediar manualmente (reenvio manual ou contato direto com o cliente).
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('SendAppointmentConfirmedMailJob: falhou após todas as tentativas.', [
            'appointment_id'  => $this->appointmentId,
            'attempts'        => $this->tries,
            'exception_class' => $exception !== null ? get_class($exception) : null,
            'exception'       => $exception?->getMessage(),
        ]);
    }
}
