<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $token;
    public $role;

    public function __construct(string $token, string $role)
    {
        $this->token = $token;
        $this->role = $role;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Admin Invitation to INHALIQ',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin_invitation',
            with: [
                'token' => $this->token,
                'role' => $this->role,
                'activationUrl' => env('FRONTEND_URL', 'http://localhost:3000') . '/admin/activate?token=' . $this->token,
            ],
        );
    }
}
