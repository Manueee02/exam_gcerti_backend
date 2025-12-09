<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AuditorCredentials extends Mailable
{
    use Queueable, SerializesModels;

    public $auditor;
    public $email;
    public $password;

    /**
     * Create a new message instance.
     *
     * @param $auditor
     * @param $email
     * @param $password
     */
    public function __construct($auditor, $email, $password)
    {
        $this->auditor = $auditor;
        $this->email = $email;
        $this->password = $password;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('Credenziali di accesso - Gcerti Italy')
            ->view('emails.auditor_credentials');
    }
}
