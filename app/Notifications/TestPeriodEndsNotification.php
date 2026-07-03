<?php

namespace App\Notifications;

use App\Events\EmailSent;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class TestPeriodEndsNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $link = "https://cwplatform.ru/panel/user/profile/";

        $recipientEmail = $notifiable->email;
        $recipientName = $notifiable->name;
        $recipientId = $notifiable->id;

        $from = 'noreply@' . config('app.APP_DOMAIN');

        $subject = 'Заканчивается тестовый период';

        $body = view('emails.end_of_test_period', [
            'name' => $notifiable->name,
            'link' => $link,
            'year' => Date('Y')
        ])->render();

        EmailSent::dispatch(
            $recipientEmail,
            $subject,
            $body,
            $meta = [
                'recipient_id' => $recipientId,
                'recipient_name' => $recipientName
            ],
            $type = 'notification',
            $status = 'sent'
        );


        return (new MailMessage)
            ->view(
                'emails.end_of_test_period',
                [
                    'name' => $notifiable->name,
                    'link' => $link,
                    'year' => Date('Y')
                ]
            )
            ->from($from, 'CW Platform')
            ->subject($subject);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
