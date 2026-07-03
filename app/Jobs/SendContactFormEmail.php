<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendContactFormEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $to;
    protected string $subject;
    protected array $data;

    /**
     * Create a new job instance.
     */
    public function __construct(string $to, string $subject, array $data)
    {
        $this->to = $to;
        $this->subject = $subject;
        $this->data = $data;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $html = '';

        foreach ($this->data as $key => $value) {
            $html .= "<strong>{$key}:</strong> {$value}<br>";
        }

        Mail::send([], [], function ($message) use ($html) {
            $message->to($this->to)
                ->from('noreply@cwplatform.ru')
                ->subject($this->subject)
                ->html($html);
        });
    }
}
