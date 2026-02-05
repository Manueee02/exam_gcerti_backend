<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VerifyEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $token;

    /**
     * Create a new message instance.
     */
    public function __construct($user, $token)
    {
        $this->user = $user;
        $this->token = $token;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');

        return $this->subject('Verifica la tua email - Gcerti Italy')
            ->view('emails.verify_email')
            ->with([
                'user' => $this->user,
                'verificationLink' => $frontendUrl . '/authentication/verify-email/' . $this->token
            ]);
    }
}
