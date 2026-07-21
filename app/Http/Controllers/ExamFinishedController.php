<?php

namespace App\Http\Controllers;

use App\Models\ExamFinished;
use App\Models\ExamSession;
use App\Policies\Concerns\ResolvesExaminerAssignment;
use App\Services\ExamFinishedQueryService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ExamFinishedController extends Controller
{
    use AuthorizesRequests;
    use ResolvesExaminerAssignment;

    public function __construct(
        protected ExamFinishedQueryService $queries
    ) {}

    // =========================================================
    // CANDIDATO
    // =========================================================

    public function myList(Request $request)
    {
        $candidate = $request->user()->candidate;

        if (!$candidate) {
            Log::warning('ExamFinishedController@myList: utente senza candidato collegato', [
                'user_id' => $request->user()->id,
            ]);
            return response()->json(['success' => false, 'message' => 'Nessun candidato collegato'], 403);
        }

        $data = $this->queries->listForCandidate($candidate->id);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function myShow(Request $request, string $publicId)
    {
        $candidate = $request->user()->candidate;

        if (!$candidate) {
            Log::warning('ExamFinishedController@myShow: utente senza candidato collegato', [
                'user_id' => $request->user()->id,
            ]);
            return response()->json(['success' => false, 'message' => 'Nessun candidato collegato'], 403);
        }

        try {
            $examFinished = $this->queries->findForCandidate($publicId, $candidate->id);
        } catch (\Throwable $e) {
            Log::warning('ExamFinishedController@myShow: esame non trovato o non appartenente al candidato', [
                'public_id'    => $publicId,
                'candidate_id' => $candidate->id,
            ]);
            throw $e;
        }

        $this->authorize('viewOwn', $examFinished);

        // Il candidato non vede is_correct nel payload dei log — stessa
        // regola già applicata in myActivityLog su ExamSessionController.
        $data = $this->queries->withEvents($examFinished, includeSensitiveDetails: false);

        return response()->json(['success' => true, 'data' => $data]);
    }

    // =========================================================
    // EXAMINER / DECISIONMAKER
    // =========================================================

    public function sessionsList(Request $request)
    {
        $this->authorize('viewSessionsList', ExamFinished::class);

        $plannedExamIds = $this->isAdminOrSuperAdmin($request->user())
            ? null
            : $this->assignedPlannedExamIds($request->user());

        $data = $this->queries->listSessions($plannedExamIds);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function show(Request $request, string $publicId)
    {
        $examFinished = $this->queries->find($publicId);

        $this->authorize('view', $examFinished);

        $data = $this->queries->withEvents($examFinished, includeSensitiveDetails: true);

        return response()->json(['success' => true, 'data' => $data]);
    }

    // =========================================================
    // APPROVAZIONE DECISIONMAKER
    // =========================================================

    public function approve(Request $request, string $publicId)
    {
        $examFinished = $this->queries->find($publicId);

        $this->authorize('approve', $examFinished);

        $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $auditorCache = $this->resolveLinkedAuditorCache($request->user());
        if (!$auditorCache) {
            Log::error('ExamFinishedController@approve: nessun AuditorCache risolto per utente autorizzato', [
                'user_id' => $request->user()->id,
            ]);
            return response()->json(['success' => false, 'message' => 'Impossibile identificare il deliberante'], 500);
        }

        try {
            $examFinished = $this->queries->approve($examFinished, $auditorCache->id, $request->input('note'));
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $examFinished]);
    }

    public function reject(Request $request, string $publicId)
    {
        $examFinished = $this->queries->find($publicId);

        $this->authorize('approve', $examFinished); // stessa ability copre approve/reject

        $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $auditorCache = $this->resolveLinkedAuditorCache($request->user());
        if (!$auditorCache) {
            Log::error('ExamFinishedController@reject: nessun AuditorCache risolto per utente autorizzato', [
                'user_id' => $request->user()->id,
            ]);
            return response()->json(['success' => false, 'message' => 'Impossibile identificare il deliberante'], 500);
        }

        try {
            $examFinished = $this->queries->reject($examFinished, $auditorCache->id, $request->input('note'));
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $examFinished]);
    }

    // =========================================================
    // ADMIN / SUPERADMIN
    // =========================================================

    public function adminSessionsList(Request $request)
    {
        $this->authorize('viewAllSessions', ExamFinished::class);

        $data = $this->queries->listSessions(null);

        return response()->json(['success' => true, 'data' => $data]);
    }


// ...

    public function exportPdf(Request $request, string $publicId)
    {
        $examFinished = $this->queries->find($publicId);

        $this->authorize('export', $examFinished);

        return $this->buildPdfResponse($examFinished);
    }

    private function buildPdfResponse($examFinished)
    {
        $runStatusLabel = [
            'completed' => 'Completato',
            'timeout' => 'Timeout',
            'terminated' => 'Terminato',
        ][$examFinished->run_status] ?? $examFinished->run_status;

        $durationLabel = 'N/D';
        if ($examFinished->total_duration_seconds !== null) {
            $m = intdiv($examFinished->total_duration_seconds, 60);
            $s = $examFinished->total_duration_seconds % 60;
            $durationLabel = "{$m}m {$s}s";
        }

        $pdf = Pdf::loadView('exports.exam-finished', [
            'examFinished'   => $examFinished,
            'runStatusLabel' => $runStatusLabel,
            'durationLabel'  => $durationLabel,
        ]);

        $candidateSlug = $examFinished->candidate
            ? str_replace(' ', '_', $examFinished->candidate->surname . '_' . $examFinished->candidate->name)
            : 'esame';

        return $pdf->download("esito_esame_{$candidateSlug}.pdf");
    }
}
