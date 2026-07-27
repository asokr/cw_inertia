<?php

namespace App\Notifications;

use App\Events\EmailSent;
use App\Support\SubscriberLimitLabels;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class LimitEndsNotification extends Notification
{
    use Queueable;

    private $data;
    /**
     * Create a new notification instance.
     */
    public function __construct($data)
    {
        $this->data = $data;
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
        $limitKey = (string) ($this->data['limit'] ?? '');
        $limit = SubscriberLimitLabels::label($limitKey);

        $recipientEmail = $notifiable->email;
        $recipientName = $notifiable->name;
        $recipientId = $notifiable->id;

        $subject = 'Лимиты подходят к концу';

        $from = 'noreply@' . config('app.APP_DOMAIN');

        $body = view('emails.limits_ends', [
            'name' => $notifiable->name,
            'limit' => $limit,
            'year' => date('Y')
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
                'emails.limits_ends',
                [
                    'name' => $notifiable->name,
                    'limit' => $limit,
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
