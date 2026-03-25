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

class PlannedExamInscriptionController extends Controller
{
    use AuthorizesRequests;

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
     * Gestisce tutti i passaggi del workflow:
     *
     *  sended            → waiting_payment  (admin, richiede unsigned_document + unsigned_invoice)
     *  sended            → revoked          (admin, richiede note)
     *  waiting_payment   → sended_payment   (candidato, carica document + invoice firmati)
     *  waiting_payment   → revoked          (admin, richiede note)
     *  sended_payment    → approved         (admin)
     *  sended_payment    → revoked          (admin, richiede note)
     *  qualsiasi         → retired          (candidato)
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status'             => 'required|in:revoked,sended,waiting_payment,sended_payment,approved,retired',
            'note'               => 'nullable|string|max:2000',
            'unsigned_document'  => 'nullable|integer|exists:media,id',
            'unsigned_invoice'   => 'nullable|integer|exists:media,id',
            'document'           => 'nullable|integer|exists:media,id',
            'invoice'            => 'nullable|integer|exists:media,id',
        ]);

        DB::beginTransaction();

        try {
            $inscription = PlannedExamInscription::with('candidate')->findOrFail($id);

            $this->validateStatusTransition($inscription->status, $request->status);

            // ── waiting_payment: l'admin carica i template da far firmare ─────────
            if ($request->status === 'waiting_payment') {
                if (empty($request->unsigned_document) || empty($request->unsigned_invoice)) {
                    throw new \Exception(
                        'Per passare a "In attesa di pagamento" è obbligatorio allegare unsigned_document e unsigned_invoice.'
                    );
                }
                $inscription->unsigned_document = $request->unsigned_document;
                $inscription->unsigned_invoice  = $request->unsigned_invoice;
            }

            // ── sended_payment: il candidato carica i file firmati ────────────────
            if ($request->status === 'sended_payment') {
                if (empty($request->document) || empty($request->invoice)) {
                    throw new \Exception(
                        'Per confermare il pagamento è obbligatorio allegare document e invoice firmati.'
                    );
                }
                $inscription->document = $request->document;
                $inscription->invoice  = $request->invoice;
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
                    'id_candidate'   => $inscription->id_candidate,
                    'id_planned_exam' => $inscription->id_planned_exam,
                ]);
            }

            $inscription->status = $request->status;
            $inscription->save();

            $this->sendMail($inscription);

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
    private function sendMail(PlannedExamInscription $inscription): void
    {
        $message = $this->getStatusMessage($inscription->status);

        Mail::to($inscription->candidate->email)
            ->send(new CandidateInscriptionMail($inscription, $message));
    }

    /**
     * TESTI STATUS
     */
    private function getStatusMessage(string $status): string
    {
        return match ($status) {
            'sended'          => 'Richiesta inviata',
            'waiting_payment' => 'Richiesta validata – in attesa di pagamento. Trovi i documenti da firmare nella tua area riservata.',
            'sended_payment'  => 'Documenti firmati ricevuti – pagamento in verifica.',
            'approved'        => 'Iscrizione approvata.',
            'revoked'         => 'Iscrizione revocata.',
            'retired'         => 'Iscrizione ritirata.',
            default           => 'Aggiornamento iscrizione',
        };
    }
}
