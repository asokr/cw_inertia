<?php

namespace App\Notifications;

use App\Events\EmailSent;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($token)
    {
        $this->token = $token;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $link = "https://cwplatform.ru/auth/reset-password/" . $this->token;

        $recipientEmail = $notifiable->email;
        $recipientName = $notifiable->name;
        $recipientId = $notifiable->id;

        $from = 'noreply@' . config('app.APP_DOMAIN');

        $subject = 'Восстановление пароля';

        $body = view('emails.reset_password', [
            'name' => $notifiable->name,
            'url' => $link,
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
            $type = 'system',
            $status = 'sent'
        );

        return (new MailMessage)
            ->view(
                'emails.reset_password',
                [
                    'name' => $notifiable->name,
                    'url' => $link,
                    'year' => Date('Y')
                ]
            )
            ->from($from, 'CW Platform')
            ->subject($subject);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }
}
