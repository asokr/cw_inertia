<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EmailSent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $to;
    public string $subject;
    public string $body;
    public string $type;
    public string $status;
    public array $meta;

    /**
     * Create a new event instance.
     *
     * @param string $to Email получателя (адрес)
     * @param string $subject Тема письма
     * @param string $body HTML/текст письма (рендер)
     * @param string $type Тип письма (например, "notification", "alert" и т.д.)
     * @param string $status Статус отправки (например, "sent", "failed" и т.д.)
     */
    public function __construct(
        string $to,
        string $subject,
        string $body,
        array $meta,
        string $type,
        string $status
    ) {

        $this->to = $to;
        $this->subject = $subject;
        $this->body = $body;
        $this->meta = $meta;
        $this->type = $type;
        $this->status = $status;
    }
}
