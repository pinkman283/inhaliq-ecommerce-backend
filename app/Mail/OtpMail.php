<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public $otp;
    public $purpose;

    public function __construct(string $otp, string $purpose)
    {
        $this->otp = $otp;
        $this->purpose = $purpose;
    }

    public function envelope(): Envelope
    {
        $subject = 'Your Verification Code';
        if ($this->purpose === 'forgot_password') {
            $subject = 'Password Reset Verification Code';
        }

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
            with: [
                'otp' => $this->otp,
                'purpose' => $this->purpose,
            ],
        );
    }
}
