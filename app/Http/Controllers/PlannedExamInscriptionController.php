<?php

namespace App\Http\Controllers;

use App\Mail\CandidateInscriptionMail;
use App\Models\PlannedExamInscription;
use App\Models\PlannedExamCandidate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;

class PlannedExamInscriptionController extends Controller
{
    /**
     * CREA RICHIESTA
     */
    public function store(Request $request)
    {
        $request->validate([
            'id_planned_exam' => 'required|exists:planned_exams,id',
            'id_candidate' => 'required|exists:candidates,id',
        ]);

        // evita doppia richiesta
        $exists = PlannedExamInscription::where([
            'id_planned_exam' => $request->id_planned_exam,
            'id_candidate' => $request->id_candidate,
        ])->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Richiesta già esistente'
            ], 422);
        }

        $inscription = PlannedExamInscription::create([
            'id_planned_exam' => $request->id_planned_exam,
            'id_candidate' => $request->id_candidate,
            'status' => 'sended',
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

    public function byCandidate($id)
    {
        return PlannedExamInscription::with([
            'plannedExam',
            'plannedExam.exam',
            'plannedExam.testCenter'
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
        return PlannedExamInscription::with([
            'plannedExam',
            'candidate',
            'documentMedia',
            'invoiceMedia'
        ])->findOrFail($id);
    }

    /**
     * CAMBIO STATUS
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:revoked,sended,waiting_payment,sended_payment,approved',
        ]);

        DB::beginTransaction();

        try {
            $inscription = PlannedExamInscription::with('candidate')->findOrFail($id);

            // 👉 controllo flusso (IMPORTANTISSIMO)
            $this->validateStatusTransition($inscription->status, $request->status);

            $inscription->status = $request->status;
            $inscription->save();

            // 👉 se approvata → inserisci candidato
            if ($request->status === 'approved') {
                PlannedExamCandidate::firstOrCreate([
                    'id_candidate' => $inscription->id_candidate,
                    'id_planned_exam' => $inscription->id_planned_exam,
                ]);
            }

            $this->sendMail($inscription);

            DB::commit();

            return response()->json($inscription);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * VALIDAZIONE FLUSSO STATUS (STATE MACHINE)
     */
    private function validateStatusTransition($current, $new)
    {
        $allowed = [
            'sended' => ['waiting_payment', 'revoked'],
            'waiting_payment' => ['sended_payment', 'revoked'],
            'sended_payment' => ['approved', 'revoked'],
            'approved' => [],
            'revoked' => [],
        ];

        if (!in_array($new, $allowed[$current] ?? [])) {
            throw new \Exception("Transizione stato non valida: $current → $new");
        }
    }

    /**
     * INVIO MAIL
     */
    private function sendMail($inscription)
    {
        $message = $this->getStatusMessage($inscription->status);

        Mail::to($inscription->candidate->email)
            ->send(new CandidateInscriptionMail($inscription, $message));
    }

    /**
     * TESTI STATUS
     */
    private function getStatusMessage($status)
    {
        return match ($status) {
            'sended' => 'Richiesta inviata',
            'waiting_payment' => 'Richiesta approvata - in attesa di pagamento',
            'sended_payment' => 'Pagamento inviato',
            'approved' => 'Iscrizione approvata',
            'revoked' => 'Iscrizione revocata',
            default => 'Aggiornamento iscrizione',
        };
    }
}
