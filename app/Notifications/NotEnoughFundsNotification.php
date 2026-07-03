<?php

namespace App\Notifications;

use App\Events\EmailSent;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class NotEnoughFundsNotification extends Notification
{
    use Queueable;

    private $not_enough;

    /**
     * Create a new notification instance.
     */
    public function __construct($not_enough)
    {
        $this->not_enough = $not_enough;
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
        $link = "https://cwplatform.ru/panel/user/profile/?set=" . $this->not_enough;

        $recipientEmail = $notifiable->email;
        $recipientName = $notifiable->name;
        $recipientId = $notifiable->id;

        $from = 'noreply@' . config('app.APP_DOMAIN');

        $subject = 'Не хватает средств';

        $body = view('emails.not_enough_funds', [
            'name' => $notifiable->name,
            'not_enough' => $this->not_enough,
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
                'emails.not_enough_funds',
                [
                    'name' => $notifiable->name,
                    'not_enough' => $this->not_enough,
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
