<?php

namespace App\Notifications;

use App\Events\EmailSent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WbCabinetAuthorizationNotification extends Notification
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
        $type = $this->data['type'];
        $cabinet = $this->data['cabinet'];

        $recipientEmail = $notifiable->email;
        $recipientName = $notifiable->name;
        $recipientId = $notifiable->id;

        $from = 'noreply@' . config('app.APP_DOMAIN');

        switch ($type) {
            case 'feedbacks':
                $service = 'Управление отзывами';
                $link = "https://cwplatform.ru/panel/wb/feedbacks";
                $reason = "Ошибка при авторизации по API ключу.";
                break;
            case 'feedbacks_cant_answer':
                $service = 'Управление отзывами';
                $link = "https://cwplatform.ru/panel/wb/feedbacks";
                $reason = "Ошибка при ответе на отзыв. Вероятно, не хватает прав. Проверьте права у API ключа.";
                break;
            case 'repricer_stocks':
                $service = 'Репрайсер цен';
                $link = "https://cwplatform.ru/panel/wb/repricer";
                $reason = "Ошибка авторизации при обновлении остатков. Проверьте ключ API и права доступа.";
                break;
            case 'profitability':
                $service = 'Рентабельность WB';
                $link = "https://cwplatform.ru/panel/wb/profitability";
                $reason = "Срок действия API токена истёк. Обновите токен кабинета и перезапустите расчёт.";
                break;
            default:
                $service = 'CW Platform';
                $link = "https://cwplatform.ru/panel";
                $reason = "Ошибка авторизации кабинета.";
                break;
        }

        $subject = 'Ошибка авторизации кабинета на WB';
        $body = view('emails.wb_auth_error', [
            'name' => $notifiable->name,
            'cabinet' => $cabinet,
            'link' => $link,
            'reason' => $reason,
            'service' => $service,
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
                'emails.wb_auth_error',
                [
                    'name' => $notifiable->name,
                    'cabinet' => $cabinet,
                    'link' => $link,
                    'reason' => $reason,
                    'service' => $service,
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
