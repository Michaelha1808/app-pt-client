<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReengagementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Chúng tôi nhớ bạn! 😊 Quay lại theo dõi sức khoẻ nào',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reengagement',
            with: [
                'userName'    => $this->user->name ?? 'bạn',
                'dailyGoal'   => $this->user->calorie_goal ?? 2000,
                'bestStreak'  => $this->user->calorie_streak ?? 0,
                'appUrl'      => config('app.url'),
            ],
        );
    }
}
