<?php

declare(strict_types=1);


namespace App\Mail;

use App\Models\Company;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UnattendedConversationsAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Company $company,
        public readonly User $admin,
        public readonly int $unattendedCount,
        public readonly int $alertHours
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Alerta: {$this->unattendedCount} conversa(s) sem resposta — {$this->company->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.unattended-conversations-alert',
            with: [
                'company' => $this->company,
                'admin' => $this->admin,
                'unattendedCount' => $this->unattendedCount,
                'alertHours' => $this->alertHours,
                'inboxUrl' => config('app.url') . '/minha-conta/conversas',
            ],
        );
    }
}
