<?php

namespace App\Http\Controllers;

use App\Models\ExamFinished;
use App\Models\ExamSession;
use App\Policies\Concerns\ResolvesExaminerAssignment;
use App\Services\ExamFinishedQueryService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ExamFinishedController extends Controller
{
    use AuthorizesRequests;
    use ResolvesExaminerAssignment; // per assignedPlannedExamIds() nel controller

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
            $examFinished = $this->queries->showForCandidate($publicId, $candidate->id);
        } catch (\Throwable $e) {
            Log::warning('ExamFinishedController@myShow: esame non trovato o non appartenente al candidato', [
                'public_id'    => $publicId,
                'candidate_id' => $candidate->id,
            ]);
            throw $e;
        }

        $this->authorize('viewOwn', $examFinished);

        return response()->json(['success' => true, 'data' => $examFinished]);
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

    public function sessionShow(Request $request, string $sessionPublicId)
    {
        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

        $this->authorize('viewSession', $session);

        $data = $this->queries->sessionCandidatesSummary($session->id);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function show(Request $request, string $publicId)
    {
        $examFinished = $this->queries->show($publicId);

        $this->authorize('view', $examFinished);

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
}
