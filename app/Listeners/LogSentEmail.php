<?php

namespace App\Listeners;

use App\Models\SentEmail;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class LogSentEmail
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {


        try {
            SentEmail::create([
                'to' => $event->to,
                'subject' => $event->subject,
                'body' => $event->body,
                'type' => $event->type,
                'status' => $event->status,
                'meta' => $event->meta,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to save SentEmail', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
