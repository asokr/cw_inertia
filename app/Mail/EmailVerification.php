<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmailVerification extends Mailable
{
    use Queueable, SerializesModels;
    public $verifyUrl;
    protected $user;
    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($url, $user)
    {
        $this->verifyUrl = $url;
        $this->user = $user;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $from = config('mail.MAIL_FROM_ADDRESS');
        $subject = 'Подтверждение почты';
        return $this->to($this->user)->subject($subject)->from($from)->
            view('emails.verify', ['url' => $this->verifyUrl, 'user' => $this->user, 'year' => date('Y')]);
    }
}
