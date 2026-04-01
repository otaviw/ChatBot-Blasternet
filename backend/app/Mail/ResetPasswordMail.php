<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $resetUrl;

    public function __construct(
        public readonly User $user,
        string $token
    ) {
        $frontendUrl = rtrim(env('FRONTEND_URL', config('app.url')), '/');
        $this->resetUrl = $frontendUrl
            . '/redefinir-senha?token='
            . urlencode($token)
            . '&email='
            . urlencode($user->email);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Redefinição de senha',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reset-password',
        );
    }
}
