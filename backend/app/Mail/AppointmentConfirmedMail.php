<?php

namespace App\Mail;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AppointmentConfirmedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Appointment $appointment,
        public readonly string $timezone = 'America/Sao_Paulo'
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Agendamento confirmado',
        );
    }

    public function content(): Content
    {
        $startsAt = $this->appointment->starts_at?->setTimezone($this->timezone);
        $serviceName = $this->appointment->service?->name ?? '';
        $staffName = $this->appointment->staffProfile?->display_name
            ?: $this->appointment->staffProfile?->user?->name
            ?: null;

        return new Content(
            view: 'emails.appointment-confirmed',
            with: [
                'appointment' => $this->appointment,
                'startsAt' => $startsAt,
                'serviceName' => $serviceName,
                'staffName' => $staffName,
                'customerName' => $this->appointment->customer_name ?? '',
            ],
        );
    }
}
