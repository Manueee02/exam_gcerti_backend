<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitAnswerRequest;
use App\Models\Answer;
use App\Models\Candidate;
use App\Models\ExamSession;
use App\Models\ExamSessionCandidateRun;
use App\Models\PlannedExam;
use App\Models\Question;
use App\Services\ExamEngineService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

/*
 Request HTTP
  → Controller: $this->authorize('submitAnswer', $session)
    → ExamSessionPolicy::submitAnswer() — chi può?
      → ExamEngineService::submitAnswer() — cosa fa?*/

class ExamSessionController extends Controller
{

    use AuthorizesRequests;

    public function __construct(
        protected ExamEngineService $engine
    ) {}

    /**
     * Metadati di richiesta (IP, user agent) da agganciare ai log
     * del service. Centralizzato qui cosi' ogni metodo lo costruisce
     * allo stesso modo, senza ripetere la logica di estrazione.
     */
    private function requestMeta(Request $request): array
    {
        return [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];
    }

    /**
     * =====================================================
     * START SESSION
     * =====================================================
     */
    public function start(
        Request $request,
        string $plannedExamPublicId
    ) {

        $plannedExam = PlannedExam::where(
            'public_id',
            $plannedExamPublicId
        )->firstOrFail();

        $this->authorize(
            'start',
            $plannedExam
        );

        $session = $this->engine->startSession(
            $plannedExamPublicId,
            $this->requestMeta($request)
        );

        return response()->json([
            'success' => true,
            'data' => $session,
        ]);
    }

    /**
     * =====================================================
     * END SESSION
     * =====================================================
     */
    public function end(
        Request $request,
        string $sessionPublicId
    ) {

        $session = ExamSession::where(
            'public_id',
            $sessionPublicId
        )->firstOrFail();

        $this->authorize(
            'end',
            $session
        );

        $session = $this->engine->endSession(
            $sessionPublicId,
            $this->requestMeta($request)
        );

        return response()->json([
            'success' => true,
            'data' => $session,
        ]);
    }

    /**
     * =====================================================
     * ENABLE CANDIDATE
     * =====================================================
     */
    public function enableCandidate(
        Request $request,
        string $sessionPublicId
    ) {

        $request->validate([
            'candidate_id' => [
                'required',
                'uuid',
            ],
        ]);

        $session = ExamSession::where(
            'public_id',
            $sessionPublicId
        )->firstOrFail();

        $this->authorize(
            'enableCandidate',
            $session
        );

        $candidate = Candidate::where(
            'public_id',
            $request->candidate_id
        )->firstOrFail();

        $this->engine->enableCandidate(
            $sessionPublicId,
            $candidate->id,
            $request->user()->id,
            $this->requestMeta($request)
        );

        return response()->json([
            'success' => true,
        ]);
    }

    /**
     * =====================================================
     * GET CANDIDATE EXAM
     * =====================================================
     */
    public function getCandidateExam(
        Request $request,
        string $sessionPublicId
    ) {

        $session = ExamSession::where(
            'public_id',
            $sessionPublicId
        )->firstOrFail();

        $this->authorize(
            'accessCandidateExam',
            $session
        );

        $data = $this->engine->getCandidateExam(
            $sessionPublicId,
            $request->user()->candidate->id,
            $this->requestMeta($request)
        );

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * =====================================================
     * SUBMIT ANSWER
     * =====================================================
     */
    public function submitAnswer(
        SubmitAnswerRequest $request,
        string $sessionPublicId
    ) {

        $session = ExamSession::where(
            'public_id',
            $sessionPublicId
        )->firstOrFail();

        $this->authorize(
            'submitAnswer',
            $session
        );

        $question = Question::where(
            'public_id',
            $request->question_id
        )->firstOrFail();

        $selectedAnswer = Answer::where(
            'public_id',
            $request->answer['answer_id']
        )
            ->where('id_question', $question->id)
            ->firstOrFail();

        $answer = $this->engine->submitAnswer(
            $sessionPublicId,
            $request->user()->candidate->id,
            $question->id,
            ['answer_id' => $selectedAnswer->id],
            $request->time_spent_seconds,
            $this->requestMeta($request)
        );

        return response()->json([
            'success' => true,
            'data' => $answer,
        ]);
    }

    /**
     * =====================================================
     * SCORE
     * =====================================================
     */
    public function score(
        Request $request,
        string $sessionPublicId
    ) {

        $session = ExamSession::where(
            'public_id',
            $sessionPublicId
        )->firstOrFail();

        $this->authorize(
            'submitAnswer',
            $session
        );

        $score = $this->engine->calculateScore(
            $sessionPublicId,
            $request->user()->candidate->id,
            $this->requestMeta($request)
        );

        return response()->json([
            'success' => true,
            'data' => $score,
        ]);
    }

    /**
     * =====================================================
     * MY ACTIVITY LOG (candidato — vista filtrata, senza is_correct)
     * =====================================================
     */
    public function myActivityLog(
        Request $request,
        string $sessionPublicId
    ) {

        $session = ExamSession::where(
            'public_id',
            $sessionPublicId
        )->firstOrFail();

        // Stessa regola di accesso usata per getCandidateExam: deve
        // essere lui stesso il candidato di questa sessione.
        $this->authorize(
            'accessCandidateExam',
            $session
        );

        $data = $this->engine->getActivityLog(
            $sessionPublicId,
            $request->user()->candidate->id,
            includeSensitiveDetails: false
        );

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * =====================================================
     * CANDIDATE ACTIVITY LOG (admin/esaminatore — vista completa)
     * =====================================================
     */
    public function candidateActivityLog(
        Request $request,
        string $sessionPublicId,
        string $candidatePublicId
    ) {

        $session = ExamSession::where(
            'public_id',
            $sessionPublicId
        )->firstOrFail();

        $this->authorize(
            'viewCandidateLog',
            $session
        );

        $candidate = Candidate::where(
            'public_id',
            $candidatePublicId
        )->firstOrFail();

        $data = $this->engine->getActivityLog(
            $sessionPublicId,
            $candidate->id,
            includeSensitiveDetails: true
        );

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * GET /api/exam-sessions/active/{plannedExamPublicId}
     * Restituisce la sessione attiva per un dato planned exam.
     * Usato dal candidato per trovare la sessione appena aperta.
     */
    public function getActiveSession(
        Request $request,
        string $plannedExamPublicId
    ) {
        $plannedExam = PlannedExam::where('public_id', $plannedExamPublicId)->firstOrFail();

        $session = ExamSession::where('id_planned_exam', $plannedExam->id)
            ->where('status', 'live')
            ->first();

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Nessuna sessione attiva per questo esame',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'session_public_id'      => $session->public_id,
                'planned_exam_public_id' => $plannedExamPublicId,
            ],
        ]);
    }

    /**
     * GET /api/exam-sessions/{sessionPublicId}/runs
     * Stato di tutti i candidati nella sessione — per l'esaminatore.
     */
    public function getRuns(Request $request, string $sessionPublicId)
    {
        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

        $this->authorize('enableCandidate', $session);

        $runs = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->with(['candidate:id,public_id,name,surname'])
            ->get()
            ->map(fn ($run) => [
                'candidate_public_id' => $run->candidate->public_id,
                'candidate_name'      => $run->candidate->name . ' ' . $run->candidate->surname,
                'status'              => $run->status,
            ]);

        return response()->json(['success' => true, 'data' => $runs]);
    }

    /**
     * POST /api/exam-sessions/{sessionPublicId}/join
     * Chiamato dal frontend candidato appena entra nella pagina sessione.
     */
    public function join(Request $request, string $sessionPublicId)
    {
        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

        $this->authorize('accessCandidateExam', $session);

        $this->engine->candidateJoined(
            $sessionPublicId,
            $request->user()->candidate->id
        );

        return response()->json(['success' => true]);
    }
}
