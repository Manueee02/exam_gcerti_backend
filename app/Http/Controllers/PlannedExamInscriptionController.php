<?php

namespace App\Http\Controllers;

use App\Mail\CandidateInscriptionMail;
use App\Models\PlannedExam;
use App\Models\PlannedExamInscription;
use App\Models\PlannedExamCandidate;
use App\Services\UserService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App\Services\MediaService;

class PlannedExamInscriptionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected MediaService $mediaService,
        protected UserService $userService,
    ) {}

    /**
     * CREA RICHIESTA
     */
    public function store(Request $request)
    {
        $request->validate([
            'id_planned_exam' => 'required|exists:planned_exams,id',
            'id_candidate'    => 'required|exists:candidates,id',
        ]);

        // Recupero esame pianificato
        $plannedExam = PlannedExam::findOrFail($request->id_planned_exam);

        // 1️⃣ Controllo data (non oggi o passato)
        if (Carbon::parse($plannedExam->date)->startOfDay() <= Carbon::today()) {
            return response()->json([
                'message' => 'Non puoi iscriverti a un esame con data odierna o passata'
            ], 422);
        }

        // 2️⃣ Controllo iscrizione alla stessa tipologia esame
        $alreadySubscribed = PlannedExamInscription::where('id_candidate', $request->id_candidate)
            ->whereHas('plannedExam', function ($query) use ($plannedExam) {
                $query->where('id_exam', $plannedExam->id_exam); // stessa tipologia d'esame
            })
            ->whereNotIn('status', ['retired', 'revoked']) // escludo iscrizioni "chiuse"
            ->exists();

        if ($alreadySubscribed) {
            return response()->json([
                'message' => 'Sei già iscritto a questa tipologia di esame'
            ], 422);
        }

        // 3️⃣ Controllo duplicato esatto sulla stessa sessione
        $exists = PlannedExamInscription::where([
            'id_planned_exam' => $request->id_planned_exam,
            'id_candidate'    => $request->id_candidate,
        ])
            ->whereNotIn('status', ['retired', 'revoked'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Richiesta già esistente'], 422);
        }

        // ✅ Creazione iscrizione
        $inscription = PlannedExamInscription::create([
            'id_planned_exam' => $request->id_planned_exam,
            'id_candidate'    => $request->id_candidate,
            'status'          => 'sended',
        ]);

        // Invio email
        $this->sendCandidateMail($inscription);
        $this->sendAdminMail($inscription);

        return response()->json($inscription, 201);
    }

    /**
     * TUTTE LE RICHIESTE
     */
    public function index()
    {
        return PlannedExamInscription::with(['plannedExam', 'candidate'])->get();
    }

    /**
     * ISCRIZIONI PER CANDIDATO
     */
    public function byCandidate($id)
    {
        return PlannedExamInscription::with([
            'plannedExam',
            'plannedExam.exam',
            'plannedExam.testCenter',
        ])
            ->where('id_candidate', $id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * FILTRO PER STATUS
     */
    public function byStatus($status)
    {
        return PlannedExamInscription::with(['plannedExam', 'candidate', 'plannedExam.exam'])
            ->where('status', $status)
            ->get();
    }

    /**
     * DETTAGLIO
     */
    public function show($id)
    {
        $inscription = PlannedExamInscription::with([
            'plannedExam',
            'plannedExam.exam',
            'candidate',
            'candidate.media',
            'candidate.media.media',
            'documentMedia',
            'invoiceMedia',
            'unsignedDocumentMedia',
            'unsignedInvoiceMedia',
        ])->findOrFail($id);

        $this->authorize('view', $inscription);

        return $inscription;
    }

    /**
     * CAMBIO STATUS
     *
     * Autorizzazioni:
     *  - admin / superAdmin → tutte le transizioni, supporta force=true
     *  - user (candidato)   → solo retired e sended_payment sul proprio candidato
     *
     * Flusso normale (state machine):
     *  sended            → waiting_payment  (admin, richiede unsigned_document + unsigned_invoice)
     *  sended            → revoked          (admin, richiede note)
     *  sended            → retired          (candidato)
     *  waiting_payment   → sended_payment   (candidato, richiede document + invoice)
     *  waiting_payment   → revoked          (admin, richiede note)
     *  waiting_payment   → retired          (candidato)
     *  sended_payment    → approved         (admin)
     *  sended_payment    → revoked          (admin, richiede note)
     *  sended_payment    → retired          (candidato)
     *  approved          → retired          (candidato / admin)
     *  approved          → revoked          (admin, richiede note)
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status'            => 'required|in:revoked,sended,waiting_payment,sended_payment,approved,retired',
            'note'              => 'nullable|string|max:2000',
            'unsigned_document' => 'nullable|integer|exists:media,id',
            'unsigned_invoice'  => 'nullable|integer|exists:media,id',
            'document'          => 'nullable|integer|exists:media,id',
            'invoice'           => 'nullable|integer|exists:media,id',
            'force'             => 'nullable|boolean',
        ]);

        $inscription = PlannedExamInscription::with('candidate')->findOrFail($id);

        // ── Autorizzazione ────────────────────────────────────────────────────────
        $user = $request->user();
        $user->loadMissing('role');
        $isAdmin = in_array($user->role->name, ['admin', 'superAdmin']);

        if ($isAdmin) {
            // Gli admin possono operare su qualsiasi iscrizione — nessun check aggiuntivo
        } else {
            $this->authorize('view', $inscription->candidate);

            $allowedForUser = ['retired', 'sended_payment'];
            if (!in_array($request->status, $allowedForUser, true)) {
                return response()->json([
                    'error' => 'Non sei autorizzato a impostare questo stato.',
                ], 403);
            }
        }
        // ── Fine autorizzazione ───────────────────────────────────────────────────

        DB::beginTransaction();

        try {
            $isForced = $isAdmin && (bool) $request->input('force', false);

            // Stato retired: nessuna modifica consentita, nemmeno con force
            if ($inscription->status === 'retired') {
                throw new \Exception('Impossibile modificare un\'iscrizione ritirata.');
            }

            // ── sended: pulisce tutti i media collegati ───────────────────────────
            if ($request->status === 'sended') {
                $this->clearInscriptionMedia($inscription);
            }

            // ── approved / retired / revoked: pulisce i template non firmati ──────
            if (in_array($request->status, ['approved', 'retired', 'revoked'])) {
                $this->clearUnsignedMedia($inscription);
            }

            // Validazione transizione — saltata se force === true
            if (!$isForced) {
                $this->validateStatusTransition($inscription->status, $request->status);
            }

            // ── waiting_payment: l'admin carica i template da far firmare ─────────
            if ($request->status === 'waiting_payment') {
                if (!$isForced && (empty($request->unsigned_document) || empty($request->unsigned_invoice))) {
                    throw new \Exception(
                        'Per passare a "In attesa di pagamento" è obbligatorio allegare unsigned_document e unsigned_invoice.'
                    );
                }
                if (!empty($request->unsigned_document)) {
                    $inscription->unsigned_document = $request->unsigned_document;
                }
                if (!empty($request->unsigned_invoice)) {
                    $inscription->unsigned_invoice = $request->unsigned_invoice;
                }
            }

            // ── sended_payment: il candidato carica i file firmati ────────────────
            if ($request->status === 'sended_payment') {
                if (!$isForced && (empty($request->document) || empty($request->invoice))) {
                    throw new \Exception(
                        'Per confermare il pagamento è obbligatorio allegare document e invoice firmati.'
                    );
                }
                if (!empty($request->document)) {
                    $inscription->document = $request->document;
                }
                if (!empty($request->invoice)) {
                    $inscription->invoice = $request->invoice;
                }
            }

            // ── revoked: note obbligatorie ────────────────────────────────────────
            if ($request->status === 'revoked') {
                if (empty($request->note)) {
                    throw new \Exception('La motivazione (note) è obbligatoria per revocare un\'iscrizione.');
                }
                $inscription->note = $request->note;
            }

            // Salvo il vecchio status prima di aggiornarlo (serve per il check del candidato)
            $previousStatus = $inscription->status;

            $inscription->status = $request->status;

            // ── approved: crea il record candidato nell'esame ─────────────────────
            if ($request->status === 'approved') {
                PlannedExamCandidate::firstOrCreate([
                    'id_candidate'    => $inscription->id_candidate,
                    'id_planned_exam' => $inscription->id_planned_exam,
                ]);
            }

            // ── retired / revoked: rimuove il candidato dall'esame se era approved ─
            if (in_array($request->status, ['retired', 'revoked']) && $previousStatus === 'approved') {
                PlannedExamCandidate::where([
                    'id_candidate'    => $inscription->id_candidate,
                    'id_planned_exam' => $inscription->id_planned_exam,
                ])->delete();
            }

            $inscription->save();

            // ── Mail ──────────────────────────────────────────────────────────────
            $this->sendCandidateMail($inscription, $isForced);

            // Notifica admin solo su nuovi pagamenti inviati
            if ($request->status === 'sended_payment') {
                $this->sendAdminMail($inscription, $isForced);
            }

            DB::commit();

            return response()->json($inscription->fresh([
                'plannedExam',
                'plannedExam.exam',
                'candidate',
                'documentMedia',
                'invoiceMedia',
                'unsignedDocumentMedia',
                'unsignedInvoiceMedia',
            ]));

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * ELIMINA TUTTI I MEDIA COLLEGATI ALL'ISCRIZIONE (reset completo verso sended)
     */
    private function clearInscriptionMedia(PlannedExamInscription $inscription): void
    {
        $fields = ['document', 'invoice', 'unsigned_document', 'unsigned_invoice'];

        foreach ($fields as $field) {
            $mediaId = $inscription->{$field};
            if ($mediaId) {
                $this->mediaService->deleteMedia((int) $mediaId);
                $inscription->{$field} = null;
            }
        }
    }

    /**
     * ELIMINA SOLO I TEMPLATE NON FIRMATI (usato su approved / retired / revoked)
     * I documenti firmati (document, invoice) vengono conservati come storico.
     */
    private function clearUnsignedMedia(PlannedExamInscription $inscription): void
    {
        $fields = ['unsigned_document', 'unsigned_invoice'];

        foreach ($fields as $field) {
            $mediaId = $inscription->{$field};
            if ($mediaId) {
                $this->mediaService->deleteMedia((int) $mediaId);
                $inscription->{$field} = null;
            }
        }
    }

    /**
     * VALIDAZIONE FLUSSO STATUS (STATE MACHINE)
     */
    private function validateStatusTransition(string $current, string $new): void
    {
        $allowed = [
            'sended'          => ['waiting_payment', 'revoked', 'retired'],
            'waiting_payment' => ['sended_payment',  'revoked', 'retired'],
            'sended_payment'  => ['approved',         'revoked', 'retired'],
            'approved'        => ['retired', 'revoked'],
            'revoked'         => [],
            'retired'         => [],
        ];

        if (!in_array($new, $allowed[$current] ?? [], true)) {
            throw new \Exception("Transizione stato non valida: $current → $new");
        }
    }

    /**
     * MAIL AL CANDIDATO
     */
    private function sendCandidateMail(PlannedExamInscription $inscription, bool $isOverride = false): void
    {
        $payload = $this->getCandidateMailPayload($inscription->status, $isOverride);

        Mail::to($inscription->candidate->email)
            ->send(new CandidateInscriptionMail($inscription, $payload, 'candidate'));
    }

    /**
     * MAIL AGLI ADMIN
     * Viene inviata solo agli admin con notifica "inscriptions" attiva.
     */
    private function sendAdminMail(PlannedExamInscription $inscription, bool $isOverride = false): void
    {
        $admins = $this->userService->getAdminUsersByNotificationType('inscriptions');

        if ($admins->isEmpty()) {
            return;
        }

        $payload = $this->getAdminMailPayload($inscription->status, $isOverride);

        foreach ($admins as $admin) {
            Mail::to($admin->email)
                ->send(new CandidateInscriptionMail($inscription, $payload, 'admin'));
        }
    }

    /**
     * CONTENUTO MAIL CANDIDATO PER OGNI STATO
     *
     * Ritorna: [ 'subject' => ..., 'title' => ..., 'body' => ... ]
     */
    private function getCandidateMailPayload(string $status, bool $isOverride = false): array
    {
        if ($isOverride) {
            return match ($status) {
                'sended' => [
                    'subject' => 'La tua iscrizione è stata reimpostata',
                    'title'   => 'Iscrizione reimpostata dall\'amministratore',
                    'body'    => 'La tua iscrizione è stata reimpostata allo stato iniziale da un amministratore. I documenti precedentemente caricati sono stati rimossi. Puoi accedere alla piattaforma per verificare la situazione aggiornata.',
                ],
                'waiting_payment' => [
                    'subject' => 'Documenti aggiornati – attesa pagamento',
                    'title'   => 'Iscrizione reimpostata in attesa di pagamento,',
                    'body'    => 'La tua iscrizione è stata reimpostata in attesa di pagamento da un amministratore. I documenti aggiornati sono disponibili nella tua area riservata. Scaricali, firmali e carica i file richiesti per procedere.',
                ],
                'sended_payment' => [
                    'subject' => 'Pagamento reimpostato dall\'amministratore',
                    'title'   => 'Iscrizione reimpostata a pagamento inviato,',
                    'body'    => 'La tua iscrizione è stata reimpostata allo stato "pagamento inviato" da un amministratore. Puoi accedere alla piattaforma per ulteriori informazioni.',
                ],
                'revoked' => [
                    'subject' => 'La tua iscrizione è stata revocata',
                    'title'   => 'Iscrizione revocata dall\'amministratore',
                    'body'    => 'La tua iscrizione è stata revocata da un amministratore. Consulta la piattaforma per leggere le motivazioni. Se hai dubbi, contatta il nostro supporto.',
                ],
                default => [
                    'subject' => 'Aggiornamento sulla tua iscrizione',
                    'title'   => 'Stato iscrizione aggiornato',
                    'body'    => 'Lo stato della tua iscrizione è stato aggiornato da un amministratore. Accedi alla piattaforma per i dettagli.',
                ],
            };
        }

        return match ($status) {
            'sended' => [
                'subject' => 'Richiesta di iscrizione inviata',
                'title'   => 'Abbiamo ricevuto la tua richiesta',
                'body'    => 'La tua richiesta di iscrizione è stata inviata correttamente. Il nostro staff la verificherà a breve e riceverai un aggiornamento via e-mail non appena ci saranno novità.',
            ],
            'waiting_payment' => [
                'subject' => 'Azione richiesta – documenti da firmare',
                'title'   => 'La tua iscrizione è in attesa di pagamento',
                'body'    => 'La tua richiesta di iscrizione è stata validata. Ti confermiamo che sei in possesso dei requisiti per poter accedere all\'esame. Per completare la procedura, accedi alla tua area riservata, scarica i documenti allegati, firmali e caricali insieme alla ricevuta di pagamento.',
            ],
            'sended_payment' => [
                'subject' => 'Documenti ricevuti – pagamento in verifica',
                'title'   => 'Stiamo verificando il tuo pagamento',
                'body'    => 'Abbiamo ricevuto i documenti firmati e la ricevuta di pagamento. Il nostro staff provvederà alla verifica nel minor tempo possibile. Riceverai una conferma via e-mail al termine del controllo.',
            ],
            'approved' => [
                'subject' => 'Iscrizione approvata!',
                'title'   => 'La tua iscrizione è stata approvata',
                'body'    => 'Congratulazioni! La tua iscrizione è stata approvata ufficialmente. Accedi alla piattaforma per consultare tutti i dettagli relativi all\'esame.',
            ],
            'revoked' => [
                'subject' => 'La tua iscrizione è stata revocata',
                'title'   => 'Iscrizione revocata',
                'body'    => 'La tua iscrizione è stata revocata. Puoi consultare la motivazione nella tua area riservata. Per qualsiasi chiarimento non esitare a contattarci.',
            ],
            'retired' => [
                'subject' => 'Hai ritirato la tua iscrizione',
                'title'   => 'Iscrizione ritirata',
                'body'    => 'La tua richiesta di iscrizione è stata ritirata con successo. Se cambierai idea potrai presentare una nuova richiesta in qualsiasi momento.',
            ],
            default => [
                'subject' => 'Aggiornamento sulla tua iscrizione',
                'title'   => 'Stato iscrizione aggiornato',
                'body'    => 'Lo stato della tua iscrizione è stato aggiornato. Accedi alla piattaforma per i dettagli.',
            ],
        };
    }

    /**
     * CONTENUTO MAIL ADMIN PER OGNI STATO
     *
     * Ritorna: [ 'subject' => ..., 'title' => ..., 'body' => ... ]
     */
    private function getAdminMailPayload(string $status, bool $isOverride = false): array
    {
        return match ($status) {
            'sended' => [
                'subject' => 'Nuova richiesta di iscrizione ricevuta',
                'title'   => 'È arrivata una nuova iscrizione',
                'body'    => 'Un candidato ha inviato una nuova richiesta di iscrizione. Accedi alla piattaforma per visualizzare i dettagli e procedere con la verifica.',
            ],
            'sended_payment' => [
                'subject' => 'Pagamento inviato da un candidato',
                'title'   => 'Documenti firmati e pagamento ricevuti',
                'body'    => 'Un candidato ha caricato i documenti firmati e la ricevuta di pagamento. Accedi alla piattaforma per verificare i file e procedere con l\'approvazione.',
            ],
            default => [
                'subject' => 'Aggiornamento iscrizione candidato',
                'title'   => 'Stato iscrizione aggiornato',
                'body'    => 'Lo stato di un\'iscrizione è stato aggiornato. Accedi alla piattaforma per i dettagli.',
            ],
        };
    }
}
