<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

class AuthVerificationCode extends Notification
{
    public function __construct(#[SensitiveParameter] public readonly string $code, public readonly string $purpose) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->purpose === 'registration' ? 'Verify your Green Tasty email' : 'Reset your Green Tasty password')
            ->greeting('Your Green Tasty verification code')
            ->line($this->code)
            ->line('This code expires in 10 minutes and can be used once.')
            ->line('If you did not request this code, ignore this email.');
    }
}
