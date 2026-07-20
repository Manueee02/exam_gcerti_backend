<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubmitAnswerRequest;
use App\Http\Requests\SubmitLevelRequest;
use App\Models\Answer;
use App\Models\Candidate;
use App\Models\ExamSession;
use App\Models\ExamSessionCandidateRun;
use App\Models\PlannedExam;
use App\Models\Question;
use App\Services\ExamEngineService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

        try {
            $session = $this->engine->startSession(
                $plannedExamPublicId,
                $this->requestMeta($request)
            );
        } catch (\Throwable $e) {
            Log::warning('ExamSessionController@start: apertura sessione fallita', [
                'planned_exam_public_id' => $plannedExamPublicId,
                'user_id'                => $request->user()->id ?? null,
                'error'                  => $e->getMessage(),
            ]);
            throw $e;
        }

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

        try {
            $session = $this->engine->endSession(
                $sessionPublicId,
                $this->requestMeta($request)
            );
        } catch (\Throwable $e) {
            Log::warning('ExamSessionController@end: chiusura sessione fallita', [
                'session_public_id' => $sessionPublicId,
                'user_id'           => $request->user()->id ?? null,
                'error'             => $e->getMessage(),
            ]);
            throw $e;
        }

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

        try {
            $this->engine->enableCandidate(
                $sessionPublicId,
                $candidate->id,
                $request->user()->id,
                $this->requestMeta($request)
            );
        } catch (\Throwable $e) {
            Log::error('ExamSessionController@enableCandidate: abilitazione candidato fallita', [
                'session_public_id' => $sessionPublicId,
                'candidate_id'      => $candidate->id,
                'error'             => $e->getMessage(),
            ]);
            throw $e;
        }

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

        try {
            $data = $this->engine->getCandidateExam(
                $sessionPublicId,
                $request->user()->candidate->id,
                $this->requestMeta($request)
            );
        } catch (\Throwable $e) {
            Log::warning('ExamSessionController@getCandidateExam: recupero esame fallito', [
                'session_public_id' => $sessionPublicId,
                'candidate_id'      => $request->user()->candidate->id ?? null,
                'error'             => $e->getMessage(),
            ]);
            throw $e;
        }

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

        try {
            $answer = $this->engine->submitAnswer(
                $sessionPublicId,
                $request->user()->candidate->id,
                $question->id,
                ['answer_id' => $selectedAnswer->id],
                $request->time_spent_seconds,
                $this->requestMeta($request)
            );
        } catch (\Throwable $e) {
            Log::warning('ExamSessionController@submitAnswer: invio risposta fallito', [
                'session_public_id' => $sessionPublicId,
                'candidate_id'      => $request->user()->candidate->id ?? null,
                'question_id'       => $question->id,
                'error'             => $e->getMessage(),
            ]);
            throw $e;
        }

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
// DOPO
    public function score(
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

        try {
            $score = $this->engine->calculateScore(
                $sessionPublicId,
                $request->user()->candidate->id,
                $this->requestMeta($request)
            );
        } catch (\Throwable $e) {
            Log::error('ExamSessionController@score: calcolo punteggio fallito', [
                'session_public_id' => $sessionPublicId,
                'candidate_id'      => $request->user()->candidate->id ?? null,
                'error'             => $e->getMessage(),
            ]);
            throw $e;
        }

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
            Log::warning('ExamSessionController@getActiveSession: nessuna sessione live trovata', [
                'planned_exam_public_id' => $plannedExamPublicId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Nessuna sessione attiva per questo esame',
            ]);
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

        $data = $this->engine->candidateJoined(
            $sessionPublicId,
            $request->user()->candidate->id
        );

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /api/exam-sessions/{sessionPublicId}/terminate-candidate
     * L'esaminatore termina forzatamente il run di un candidato specifico.
     */
    public function terminateCandidate(
        Request $request,
        string $sessionPublicId
    ): \Illuminate\Http\JsonResponse {
        $request->validate([
            'candidate_id' => ['required', 'uuid'],
            'reason'       => ['required', 'string', 'max:500'],
        ]);

        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();
        $this->authorize('enableCandidate', $session);

        $candidate = Candidate::where('public_id', $request->candidate_id)->firstOrFail();

        Log::warning('ExamSessionController@terminateCandidate: terminazione manuale candidato richiesta dall\'esaminatore', [
            'session_public_id' => $sessionPublicId,
            'candidate_id'      => $candidate->id,
            'examiner_id'       => $request->user()->id ?? null,
            'reason'            => $request->reason,
        ]);

        $this->engine->terminateCandidate(
            $sessionPublicId,
            $candidate->id,
            $request->reason,
            $this->requestMeta($request)
        );

        return response()->json(['success' => true]);
    }

    // In ExamSessionController
    public function logSocketEvent(Request $request, string $sessionPublicId): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'event'   => ['required', 'string'],
            'channel' => ['required', 'string'],
            'meta'    => ['sometimes', 'array'],
        ]);

        $this->engine->logClientEvent(
            $sessionPublicId,
            $request->event,
            $request->user()->candidate?->id,
            [
                'channel'    => $request->channel,
                'ip'         => $request->ip(),
                'user_agent' => $request->userAgent(),
                'logged_at'  => now()->toIso8601String(),
                'client_meta' => $request->input('meta', []),
            ]
        );

        return response()->json(['success' => true]);
    }
    public function heartbeat(Request $request, string $sessionPublicId): \Illuminate\Http\JsonResponse
    {
        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();
        $this->authorize('accessCandidateExam', $session);

        $this->engine->heartbeat($sessionPublicId, $request->user()->candidate->id);

        return response()->json(['success' => true]);
    }

    /**
     * =====================================================
     * EXAM PROGRESS (stepper/panoramica)
     * =====================================================
     */
    public function getProgress(Request $request, string $sessionPublicId)
    {
        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

        // Stessa regola di accesso di getCandidateExam: deve essere
        // lui stesso il candidato di questa sessione.
        $this->authorize('accessCandidateExam', $session);

        $data = $this->engine->getExamProgress(
            $sessionPublicId,
            $request->user()->candidate->id
        );

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function startLevel(Request $request, string $sessionPublicId)
    {
        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();
        $this->authorize('submitAnswer', $session); // stesso vincolo: run in_progress

        try {
            $data = $this->engine->confirmLevelStart(
                $sessionPublicId,
                $request->user()->candidate->id
            );
        } catch (\Throwable $e) {
            Log::warning('ExamSessionController@startLevel: avvio livello fallito', [
                'session_public_id' => $sessionPublicId,
                'candidate_id'      => $request->user()->candidate->id ?? null,
                'error'             => $e->getMessage(),
            ]);
            throw $e;
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function submitLevel(SubmitLevelRequest $request, string $sessionPublicId)
    {
        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();
        $this->authorize('submitAnswer', $session);

        try {
            $result = $this->engine->submitLevelAnswers(
                $sessionPublicId,
                $request->user()->candidate->id,
                $request->input('answers', []),
                $this->requestMeta($request)
            );
        } catch (\Throwable $e) {
            Log::error('ExamSessionController@submitLevel: invio livello fallito', [
                'session_public_id' => $sessionPublicId,
                'candidate_id'      => $request->user()->candidate->id ?? null,
                'error'             => $e->getMessage(),
            ]);
            throw $e;
        }

        return response()->json(['success' => true, 'data' => $result]);
    }


}
