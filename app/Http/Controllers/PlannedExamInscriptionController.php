<?php

namespace App\Http\Controllers;

use App\Mail\CandidateInscriptionMail;
use App\Models\Media;
use App\Models\PlannedExamInscription;
use App\Models\PlannedExamCandidate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App\Services\MediaService;

class PlannedExamInscriptionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected MediaService $mediaService) {}

    /**
     * CREA RICHIESTA
     */
    public function store(Request $request)
    {
        $request->validate([
            'id_planned_exam' => 'required|exists:planned_exams,id',
            'id_candidate'    => 'required|exists:candidates,id',
        ]);

        $exists = PlannedExamInscription::where([
            'id_planned_exam' => $request->id_planned_exam,
            'id_candidate'    => $request->id_candidate,
        ])->exists();

        if ($exists) {
            return response()->json(['message' => 'Richiesta già esistente'], 422);
        }

        $inscription = PlannedExamInscription::create([
            'id_planned_exam' => $request->id_planned_exam,
            'id_candidate'    => $request->id_candidate,
            'status'          => 'sended',
        ]);

        $this->sendMail($inscription);

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

        // ── Carico l'iscrizione con il candidato ──────────────────────────────────
        $inscription = PlannedExamInscription::with('candidate')->findOrFail($id);

        // ── Autorizzazione ────────────────────────────────────────────────────────
        $user = $request->user();
        $user->loadMissing('role');
        $isAdmin = in_array($user->role->name, ['admin', 'superAdmin']);

        if ($isAdmin) {
            // Gli admin possono operare su qualsiasi iscrizione — nessun check aggiuntivo
        } else {
            // L'utente può operare solo sul proprio candidato
            $this->authorize('view', $inscription->candidate);

            // L'utente può impostare solo retired e sended_payment
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
            // force=true è riservato agli admin
            $isForced = $isAdmin && (bool) $request->input('force', false);

            // Stato retired: nessuna modifica consentita, nemmeno con force
            if ($inscription->status === 'retired') {
                throw new \Exception('Impossibile modificare un\'iscrizione ritirata.');
            }

            // ── sended / retired: pulisce tutti i media collegati ────────────────
            if (in_array($request->status, ['sended', 'retired'])) {
                $this->clearInscriptionMedia($inscription);
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

            // ── approved: crea il record candidato nell'esame ─────────────────────
            if ($request->status === 'approved') {
                PlannedExamCandidate::firstOrCreate([
                    'id_candidate'    => $inscription->id_candidate,
                    'id_planned_exam' => $inscription->id_planned_exam,
                ]);
            }

            $inscription->status = $request->status;
            $inscription->save();

            $this->sendMail($inscription, $isForced);

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
     * ELIMINA TUTTI I MEDIA COLLEGATI ALL'ISCRIZIONE (usato al reset verso sended)
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
     * VALIDAZIONE FLUSSO STATUS (STATE MACHINE)
     *
     * retired è raggiungibile da qualsiasi stato "aperto"
     * revoked  è raggiungibile da sended, waiting_payment, sended_payment
     */
    private function validateStatusTransition(string $current, string $new): void
    {
        $allowed = [
            'sended'          => ['waiting_payment', 'revoked', 'retired'],
            'waiting_payment' => ['sended_payment',  'revoked', 'retired'],
            'sended_payment'  => ['approved',         'revoked', 'retired'],
            'approved'        => [],
            'revoked'         => [],
            'retired'         => [],
        ];

        if (!in_array($new, $allowed[$current] ?? [], true)) {
            throw new \Exception("Transizione stato non valida: $current → $new");
        }
    }

    /**
     * INVIO MAIL
     */
    private function sendMail(PlannedExamInscription $inscription, bool $isOverride = false): void
    {
        $message = $this->getStatusMessage($inscription->status, $isOverride);

        Mail::to($inscription->candidate->email)
            ->send(new CandidateInscriptionMail($inscription, $message));
    }

    /**
     * TESTI STATUS
     */
    private function getStatusMessage(string $status, bool $isOverride = false): string
    {
        if ($isOverride) {
            return match ($status) {
                'sended'          => 'La tua iscrizione è stata reimpostata allo stato iniziale da un amministratore. I documenti precedentemente caricati sono stati rimossi.',
                'waiting_payment' => 'La tua iscrizione è stata reimpostata in attesa di pagamento da un amministratore. Trovi i documenti aggiornati nella tua area riservata.',
                'sended_payment'  => 'La tua iscrizione è stata reimpostata a pagamento inviato da un amministratore.',
                'revoked'         => 'La tua iscrizione è stata revocata da un amministratore.',
                default           => 'Lo stato della tua iscrizione è stato aggiornato da un amministratore.',
            };
        }

        return match ($status) {
            'sended'          => 'Richiesta inviata.',
            'waiting_payment' => 'Richiesta approvata – in attesa di pagamento. Trovi i documenti da firmare nella tua area riservata.',
            'sended_payment'  => 'Documenti firmati ricevuti – pagamento in verifica.',
            'approved'        => 'Iscrizione approvata.',
            'revoked'         => 'Iscrizione revocata.',
            'retired'         => 'Iscrizione ritirata.',
            default           => 'Aggiornamento iscrizione',
        };
    }
}
