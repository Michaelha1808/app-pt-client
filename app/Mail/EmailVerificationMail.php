<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Mã xác thực CaloEye: {$this->code}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verify-email',
            with: [
                'userName' => $this->user->name ?? 'bạn',
                'code'     => $this->code,
            ],
        );
    }
}
