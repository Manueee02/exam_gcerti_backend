<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitAnswerRequest;
use App\Models\ExamSession;
use App\Models\PlannedExam;
use App\Services\ExamEngineService;
use Illuminate\Http\Request;

class ExamSessionController extends Controller
{
    public function __construct(
        protected ExamEngineService $engine
    ) {}

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
            $plannedExamPublicId
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
            $sessionPublicId
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
                'integer',
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

        $this->engine->enableCandidate(
            $sessionPublicId,
            $request->candidate_id,
            $request->user()->id
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
            $request->user()->candidate->id
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

        $answer = $this->engine->submitAnswer(
            $sessionPublicId,
            $request->user()->candidate->id,
            $request->question_id,
            $request->answer,
            $request->time_spent_seconds
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
            $request->user()->candidate->id
        );

        return response()->json([
            'success' => true,
            'data' => $score,
        ]);
    }
}
