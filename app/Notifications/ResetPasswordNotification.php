<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url('/app/reset-password?token=' . $this->token . '&email=' . urlencode($notifiable->getEmailForPasswordReset()));

        return (new MailMessage)
            ->subject('重設您的密碼')
            ->view('emails.reset-password', ['url' => $url, 'user' => $notifiable]);
    }
}
