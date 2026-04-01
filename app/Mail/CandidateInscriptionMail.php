<?php

namespace App\Mail;

use App\Models\PlannedExamInscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CandidateInscriptionMail extends Mailable
{
    use Queueable, SerializesModels;

    public PlannedExamInscription $inscription;
    public $candidate;

    /** Oggetto della mail */
    public string $mailSubject;

    /** Titolo visualizzato nell'header della mail */
    public string $mailTitle;

    /** Corpo testuale principale */
    public string $mailBody;

    /** Link CTA */
    public string $link;

    /**
     * @param PlannedExamInscription $inscription
     * @param array                  $payload  ['subject' => ..., 'title' => ..., 'body' => ...]
     * @param string                 $recipient 'candidate' | 'admin'
     */
    public function __construct(PlannedExamInscription $inscription, array $payload, string $recipient = 'candidate')
    {
        $this->inscription  = $inscription;
        $this->candidate    = $inscription->candidate;
        $this->mailSubject  = $payload['subject'];
        $this->mailTitle    = $payload['title'];
        $this->mailBody     = $payload['body'];

        // I link puntano alla stessa risorsa ma con path diverso per admin e candidato
        $this->link = $recipient === 'admin'
            ? url('/admin/inscriptions/' . $inscription->id)
            : url('/inscriptions/' . $inscription->id);
    }

    public function build(): static
    {
        return $this
            ->subject($this->mailSubject)
            ->view('emails.candidates.inscription');
    }
}
