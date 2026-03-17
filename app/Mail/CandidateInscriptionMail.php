<?php

namespace App\Mail;

use App\Models\PlannedExamInscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CandidateInscriptionMail extends Mailable
{
    use Queueable, SerializesModels;

    public $inscription;
    public $candidate;
    public $statusMessage;
    public $link;

    public function __construct(PlannedExamInscription $inscription, $statusMessage)
    {
        $this->inscription = $inscription;
        $this->candidate = $inscription->candidate;
        $this->statusMessage = $statusMessage;
        $this->link = url("/inscriptions/" . $inscription->id);
    }

    public function build()
    {
        return $this->subject($this->statusMessage)
            ->view('emails.candidates.inscription'); // 👈 percorso giusto
    }
}
