<?php

namespace App\Notifications;

use App\Events\EmailSent;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionCreditsEndedNotification extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $from = 'noreply@'.config('app.APP_DOMAIN');
        $subject = 'Кредиты закончились';
        $profileUrl = 'https://cwplatform.ru/panel/user/profile';

        $viewData = [
            'name' => $notifiable->name,
            'year' => date('Y'),
            'profileUrl' => $profileUrl,
        ];

        EmailSent::dispatch(
            $notifiable->email,
            $subject,
            view('emails.subscription_credits_ended', $viewData)->render(),
            [
                'recipient_id' => $notifiable->id,
                'recipient_name' => $notifiable->name,
            ],
            'notification',
            'sent'
        );

        return (new MailMessage)
            ->view('emails.subscription_credits_ended', $viewData)
            ->from($from, 'CW Platform')
            ->subject($subject);
    }
}
