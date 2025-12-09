<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AuditorCreated extends Mailable
{
    use Queueable, SerializesModels;

    public $auditor;

    public function __construct($auditor)
    {
        $this->auditor = $auditor;
    }

    public function build()
    {
        return $this->subject('Nuovo Auditor Inserito')
            ->view('emails.auditor_created');
    }
}
