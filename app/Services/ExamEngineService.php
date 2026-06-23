<?php

namespace App\Services;

use App\Models\Answer;
use App\Models\Exam;
use App\Models\ExamArea;
use App\Models\ExamExtractionRule;
use App\Models\ExamLevel;
use App\Models\ExamSession;
use App\Models\ExamSessionAnswer;
use App\Models\ExamSessionCandidateQuestion;
use App\Models\ExamSessionCandidateRun;
use App\Models\ExamSessionLog;
use App\Models\PlannedExam;
use App\Models\PlannedExamCandidate;
use App\Models\Question;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExamEngineService
{
    /**
     * =========================================================
     * START SESSION
     * =========================================================
     */
    public function startSession(string $plannedExamPublicId): ExamSession
    {
        return DB::transaction(function () use ($plannedExamPublicId) {

            /**
             * Recupero planned exam
             */
            $plannedExam = PlannedExam::where(
                'public_id',
                $plannedExamPublicId
            )->firstOrFail();

            /**
             * Controllo apertura sessione:
             * massimo 10 minuti prima dell'inizio
             */
            $datePart = $plannedExam->date instanceof \Carbon\Carbon
                ? $plannedExam->date->format('Y-m-d')
                : (string) $plannedExam->date;

            $timePart = $plannedExam->time instanceof \Carbon\Carbon
                ? $plannedExam->time->format('H:i:s')
                : (string) $plannedExam->time;

            $examDateTime = Carbon::parse($datePart . ' ' . $timePart);

            $allowedStart = $examDateTime->copy()->subMinutes(10);

            if (now()->lt($allowedStart)) {

                throw new \Exception(
                    'La sessione può essere aperta solo 10 minuti prima dell’esame'
                );
            }

            /**
             * Evita doppia sessione live
             */
            $alreadyLive = ExamSession::where(
                'id_planned_exam',
                $plannedExam->id
            )
                ->where('status', 'live')
                ->exists();

            if ($alreadyLive) {

                throw new \Exception(
                    'Esiste già una sessione attiva'
                );
            }

            /**
             * Creo sessione
             */
            $session = ExamSession::create([
                'id_planned_exam' => $plannedExam->id,
                'id_exam' => $plannedExam->id_exam,
                'status' => 'live',
                'started_at' => now(),
            ]);

            $this->logEvent(
                $session->id,
                'SESSION_STARTED',
                'system',
                null,
                [
                    'planned_exam_id' => $plannedExam->id,
                ]
            );

            /**
             * Recupero candidati
             */
            $candidates = PlannedExamCandidate::where(
                'id_planned_exam',
                $plannedExam->id
            )->get();

            foreach ($candidates as $candidate) {

                /**
                 * 1 run per candidato
                 */
                $run = ExamSessionCandidateRun::create([
                    'id_exam_session' => $session->id,
                    'id_candidate' => $candidate->id_candidate,
                    'status' => 'pending',
                    'current_step' => 1,
                ]);

                /**
                 * Domande random personalizzate
                 */
                $questions = $this->generateQuestionsForExam(
                    $plannedExam->id_exam
                );

                foreach ($questions as $index => $question) {

                    ExamSessionCandidateQuestion::create([
                        'id_candidate_run' => $run->id,
                        'id_question' => $question->id,
                        'position' => $index + 1,
                    ]);
                }

                $firstGroup = $this->resolveFirstGroup($plannedExam->id_exam);

                if ($firstGroup === null) {
                    throw new \Exception('Esame non configurato correttamente: nessuna area/livello con regole di estrazione');
                }

                $run->update([
                    'current_exam_area_id' => $firstGroup['area']->id,
                    'current_exam_level_id' => $firstGroup['level']->id,
                    'current_step_started_at' => now(),
                    'current_step' => 1,
                ]);

                $this->logEvent(
                    $session->id,
                    'QUESTIONS_ASSIGNED',
                    'candidate',
                    $candidate->id_candidate,
                    [
                        'questions_count' => count($questions),
                    ]
                );
            }

            $session->refresh();

            return $session;
        });
    }

    /**
     * =========================================================
     * ENABLE CANDIDATE
     * =========================================================
     *
     * L'esaminatore abilita il candidato
     * dopo verifica identità
     */
    public function enableCandidate(
        string $sessionPublicId,
        int $candidateId,
        int $examinerId
    ): void {

        $session = ExamSession::where(
            'public_id',
            $sessionPublicId
        )->firstOrFail();

        $run = ExamSessionCandidateRun::where(
            'id_exam_session',
            $session->id
        )
            ->where('id_candidate', $candidateId)
            ->firstOrFail();

        $run->update([
            'status' => 'authorized',
        ]);

        $this->logEvent(
            $session->id,
            'CANDIDATE_AUTHORIZED',
            'examiner',
            $examinerId,
            [
                'candidate_id' => $candidateId,
            ]
        );
    }

    /**
     * =========================================================
     * GET CANDIDATE EXAM
     * =========================================================
     */
    public function getCandidateExam(string $sessionPublicId, int $candidateId): array
    {
        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

        if ($session->status !== 'live') {
            throw new \Exception('La sessione non è attiva');
        }

        $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)
            ->firstOrFail();

        if (!in_array($run->status, ['authorized', 'in_progress'])) {
            throw new \Exception('Candidato non autorizzato');
        }

        if ($run->status === 'authorized') {
            $run->update(['status' => 'in_progress', 'started_at' => now()]);
            $this->logEvent($session->id, 'CANDIDATE_STARTED_EXAM', 'candidate', $candidateId);
        }

        $this->ensureGlobalTimeNotExpired($session, $run);
        $run = $this->ensureCurrentGroupIsValid($run, $session);

        if ($run->status === 'completed') {
            return [
                'session' => $session,
                'run' => $run,
                'exam_completed' => true,
                'current_area' => null,
                'current_level' => null,
                'questions' => [],
            ];
        }

        $questions = ExamSessionCandidateQuestion::with(['question.answers'])
            ->where('id_candidate_run', $run->id)
            ->whereHas('question', function ($q) use ($run) {
                $q->where('exam_area_id', $run->current_exam_area_id)
                    ->where('exam_level_id', $run->current_exam_level_id);
            })
            ->orderBy('position')
            ->get();

        $questions->each(function ($cq) {
            $cq->question->answers->each(fn ($a) => $a->makeHidden('is_correct'));
        });

        $rule = ExamExtractionRule::where('exam_area_id', $run->current_exam_area_id)
            ->where('exam_level_id', $run->current_exam_level_id)
            ->first();

        $exam = Exam::find($session->id_exam);

        return [
            'session' => $session,
            'run' => $run,
            'exam_completed' => false,
            'current_area' => ExamArea::find($run->current_exam_area_id),
            'current_level' => ExamLevel::find($run->current_exam_level_id),
            'level_ends_at' => $rule && $run->current_step_started_at
                ? Carbon::parse($run->current_step_started_at)->addMinutes($rule->duration_minutes)
                : null,
            'exam_ends_at' => $run->started_at
                ? Carbon::parse($run->started_at)->addMinutes($exam->duration_minutes ?? 60)
                : null,
            'questions' => $questions,
        ];
    }

    /**
     * =========================================================
     * SUBMIT ANSWER
     * =========================================================
     */
    public function submitAnswer(
        string $sessionPublicId,
        int $candidateId,
        int $questionId,
        array $answer,
        ?int $timeSpentSeconds = null
    ): ExamSessionAnswer {

        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

        if ($session->status !== 'live') {
            throw new \Exception('La sessione è chiusa');
        }

        $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)
            ->firstOrFail();

        if ($run->status !== 'in_progress') {
            throw new \Exception('Esame non attivo per il candidato');
        }

        $this->ensureGlobalTimeNotExpired($session, $run);
        $run = $this->ensureCurrentGroupIsValid($run, $session);

        if ($run->status === 'completed') {
            throw new \Exception('Esame concluso, non sono ammesse altre risposte');
        }

        $question = Question::find($questionId);

        if (
            !$question ||
            $question->exam_area_id !== $run->current_exam_area_id ||
            $question->exam_level_id !== $run->current_exam_level_id
        ) {
            $this->logEvent(
                $session->id, 'UNAUTHORIZED_QUESTION_ACCESS', 'candidate', $candidateId,
                ['question_id' => $questionId, 'reason' => 'fuori dal livello corrente']
            );
            throw new \Exception('Domanda non appartenente al livello corrente');
        }

        $assignedQuestion = ExamSessionCandidateQuestion::where('id_question', $questionId)
            ->where('id_candidate_run', $run->id)
            ->exists();

        if (!$assignedQuestion) {
            $this->logEvent(
                $session->id, 'UNAUTHORIZED_QUESTION_ACCESS', 'candidate', $candidateId,
                ['question_id' => $questionId]
            );
            throw new \Exception('Domanda non assegnata');
        }

        $alreadyAnswered = ExamSessionAnswer::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)
            ->where('id_question', $questionId)
            ->exists();

        if ($alreadyAnswered) {
            $this->logEvent(
                $session->id, 'DUPLICATE_ANSWER_ATTEMPT', 'candidate', $candidateId,
                ['question_id' => $questionId]
            );
            throw new \Exception('Domanda già risposta');
        }

        $correctAnswer = Answer::where('id_question', $questionId)
            ->where('is_correct', 'true')
            ->first();

        $isCorrect = false;
        if ($correctAnswer) {
            $selectedAnswerId = $answer['answer_id'] ?? null;
            $isCorrect = ($correctAnswer->id == $selectedAnswerId);
        }

        $savedAnswer = DB::transaction(function () use (
            $session, $candidateId, $questionId, $answer, $isCorrect, $timeSpentSeconds, $run
        ) {
            try {
                $saved = ExamSessionAnswer::create([
                    'id_exam_session' => $session->id,
                    'id_question' => $questionId,
                    'id_candidate' => $candidateId,
                    'answer' => $answer,
                    'is_correct' => $isCorrect,
                    'time_spent_seconds' => $timeSpentSeconds,
                    'id_exam_session_step' => $run->current_step,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->getCode() === '23505') {
                    $this->logEvent(
                        $session->id, 'DUPLICATE_ANSWER_ATTEMPT', 'candidate', $candidateId,
                        ['question_id' => $questionId]
                    );
                    throw new \Exception('Domanda già risposta');
                }
                throw $e;
            }

            $this->logEvent(
                $session->id, 'ANSWER_SUBMITTED', 'candidate', $candidateId,
                ['question_id' => $questionId, 'is_correct' => $isCorrect]
            );

            return $saved;
        });

        $rule = ExamExtractionRule::where('exam_area_id', $run->current_exam_area_id)
            ->where('exam_level_id', $run->current_exam_level_id)
            ->first();

        if ($rule) {
            $answeredInGroup = ExamSessionAnswer::where('id_exam_session', $session->id)
                ->where('id_candidate', $candidateId)
                ->whereIn('id_question', function ($q) use ($run) {
                    $q->select('id')->from('questions')
                        ->where('exam_area_id', $run->current_exam_area_id)
                        ->where('exam_level_id', $run->current_exam_level_id);
                })
                ->count();

            if ($answeredInGroup >= $rule->n_questions) {
                $this->finalizeCurrentGroupAndAdvance($run, $session, 'completed');
            }
        }

        $savedAnswer->makeHidden('is_correct');

        return $savedAnswer;
    }

    /**
     * =========================================================
     * CALCULATE SCORE
     * =========================================================
     */
    public function calculateScore(string $sessionPublicId, int $candidateId): array
    {
        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

        $areas = $this->getOrderedAreasWithRules($session->id_exam);
        $report = [];

        foreach ($areas as $area) {
            $levels = $this->getOrderedLevelsForArea($area->id);
            $highestPassed = null;

            foreach ($levels as $level) {
                $result = $this->computeGroupResult($session->id, $candidateId, $area->id, $level->id);

                if ($result['total'] === 0) {
                    continue;
                }
                if ($result['passed']) {
                    $highestPassed = $level;
                }
            }

            $report[] = [
                'exam_area_id' => $area->id,
                'area_name' => $area->name,
                'area_label' => $area->label,
                'highest_level_passed' => $highestPassed ? [
                    'exam_level_id' => $highestPassed->id,
                    'name' => $highestPassed->name,
                    'label' => $highestPassed->label,
                    'order' => $highestPassed->order,
                ] : null,
            ];
        }

        $this->logEvent($session->id, 'REPORT_GENERATED', 'system', $candidateId, ['report' => $report]);

        return ['areas' => $report];
    }

    /**
     * Soglia di superamento globale = media pesata dei passing_score
     * definiti per area/livello (exam_extraction_rules).
     * Transitorio: in Fase 3 la valutazione diventerà per-step
     * e questo metodo andrà sostituito.
     */
    private function resolvePassingThreshold(int $examId): float
    {
        $rules = ExamExtractionRule::whereHas('area', function ($q) use ($examId) {
            $q->where('exam_id', $examId);
        })->get();

        if ($rules->isEmpty() || $rules->sum('n_questions') === 0) {
            return 60.0; // fallback se non configurato
        }

        $weightedSum = $rules->sum(fn ($r) => $r->passing_score * $r->n_questions);

        return round($weightedSum / $rules->sum('n_questions'), 2);
    }

    /**
     * =========================================================
     * END SESSION
     * =========================================================
     */
    public function endSession(
        string $sessionPublicId
    ): ExamSession {

        return DB::transaction(function () use (
            $sessionPublicId
        ) {

            $session = ExamSession::where(
                'public_id',
                $sessionPublicId
            )->firstOrFail();

            if ($session->status !== 'live') {

                throw new \Exception(
                    'Sessione non attiva'
                );
            }

            $session->update([
                'status' => 'completed',
                'ended_at' => now(),
            ]);

            ExamSessionCandidateRun::where(
                'id_exam_session',
                $session->id
            )->whereIn('status', [
                'pending',
                'authorized',
                'in_progress'
            ])->update([
                'status' => 'completed',
                'ended_at' => now(),
            ]);

            $this->logEvent(
                $session->id,
                'SESSION_ENDED',
                'system',
                null
            );

            return $session;
        });
    }

    /**
     * =========================================================
     * QUESTION GENERATION
     * =========================================================
     */
    private function generateQuestionsForExam(
        int $examId
    ) {

        $rules = ExamExtractionRule::whereHas(
            'area',
            function ($query) use ($examId) {
                $query->where('exam_id', $examId);
            }
        )->get();

        $questions = collect();

        foreach ($rules as $rule) {

            $subset = Question::where(
                'exam_id',
                $examId
            )
                ->where(
                    'exam_area_id',
                    $rule->exam_area_id
                )
                ->where(
                    'exam_level_id',
                    $rule->exam_level_id
                )
                ->inRandomOrder()
                ->limit($rule->n_questions)
                ->get();

            if (
                $subset->count() <
                $rule->n_questions
            ) {

                throw new \Exception(
                    "Domande insufficienti per area {$rule->exam_area_id} livello {$rule->exam_level_id}"
                );
            }

            $questions = $questions->merge(
                $subset
            );
        }

        return $questions
            ->shuffle()
            ->values();
    }

    /**
     * =========================================================
     * SESSION LOGGING
     * =========================================================
     */
    private function logEvent(
        int $sessionId,
        string $eventType,
        string $actorType,
        ?int $actorId = null,
        ?array $payload = null
    ): void {

        try {

            ExamSessionLog::create([
                'id_exam_session' => $sessionId,
                'event_type' => $eventType,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'payload' => $payload,
            ]);

        } catch (\Throwable $e) {

            Log::error(
                'Exam session log error',
                [
                    'message' => $e->getMessage(),
                    'session_id' => $sessionId,
                ]
            );
        }
    }

    private function getOrderedAreasWithRules(int $examId): \Illuminate\Support\Collection
    {
        $areaIds = ExamExtractionRule::query()
            ->join('exam_areas', 'exam_areas.id', '=', 'exam_extraction_rules.exam_area_id')
            ->where('exam_areas.exam_id', $examId)
            ->pluck('exam_extraction_rules.exam_area_id')
            ->unique();

        return ExamArea::whereIn('id', $areaIds)->orderBy('order')->get();
    }

    private function getOrderedLevelsForArea(int $areaId): \Illuminate\Support\Collection
    {
        $levelIds = ExamExtractionRule::where('exam_area_id', $areaId)->pluck('exam_level_id');

        return ExamLevel::whereIn('id', $levelIds)->orderBy('order')->get();
    }

    private function resolveFirstGroup(int $examId): ?array
    {
        $areas = $this->getOrderedAreasWithRules($examId);
        if ($areas->isEmpty()) {
            return null;
        }

        $levels = $this->getOrderedLevelsForArea($areas->first()->id);
        if ($levels->isEmpty()) {
            return null;
        }

        return ['area' => $areas->first(), 'level' => $levels->first()];
    }

    private function resolveNextGroup(int $examId, int $currentAreaId, int $currentLevelId, bool $passed): ?array
    {
        if ($passed) {
            $levelsInArea = $this->getOrderedLevelsForArea($currentAreaId);
            $currentIndex = $levelsInArea->search(fn ($l) => $l->id === $currentLevelId);

            if ($currentIndex !== false && isset($levelsInArea[$currentIndex + 1])) {
                return [
                    'area' => ExamArea::find($currentAreaId),
                    'level' => $levelsInArea[$currentIndex + 1],
                ];
            }
            // Era l'ultimo livello dell'area, superato -> si passa comunque all'area successiva
        }

        $areas = $this->getOrderedAreasWithRules($examId);
        $areaIndex = $areas->search(fn ($a) => $a->id === $currentAreaId);

        if ($areaIndex === false || !isset($areas[$areaIndex + 1])) {
            return null; // nessuna area successiva: esame concluso
        }

        $nextArea = $areas[$areaIndex + 1];
        $levelsInNextArea = $this->getOrderedLevelsForArea($nextArea->id);

        if ($levelsInNextArea->isEmpty()) {
            throw new \Exception("Area {$nextArea->id} non ha regole di estrazione configurate");
        }

        return ['area' => $nextArea, 'level' => $levelsInNextArea->first()];
    }

    private function computeGroupResult(int $sessionId, int $candidateId, int $areaId, int $levelId): array
    {
        $rule = ExamExtractionRule::where('exam_area_id', $areaId)
            ->where('exam_level_id', $levelId)
            ->first();

        $questionIds = Question::where('exam_area_id', $areaId)
            ->where('exam_level_id', $levelId)
            ->pluck('id');

        $total = ExamSessionAnswer::where('id_exam_session', $sessionId)
            ->where('id_candidate', $candidateId)
            ->whereIn('id_question', $questionIds)
            ->count();

        $correct = ExamSessionAnswer::where('id_exam_session', $sessionId)
            ->where('id_candidate', $candidateId)
            ->whereIn('id_question', $questionIds)
            ->where('is_correct', true)
            ->count();

        return [
            'total' => $total,
            'correct' => $correct,
            'passing_score' => $rule->passing_score ?? null,
            'expected_total' => $rule->n_questions ?? null,
            'passed' => $rule !== null && $correct >= $rule->passing_score,
        ];
    }

    private function ensureGlobalTimeNotExpired(ExamSession $session, ExamSessionCandidateRun $run): void
    {
        if (!$run->started_at) {
            return;
        }

        $exam = Exam::find($session->id_exam);
        $durationMinutes = $exam->duration_minutes ?? 60;
        $endTime = Carbon::parse($run->started_at)->addMinutes($durationMinutes);

        if (now()->gt($endTime)) {
            $run->update(['status' => 'timeout', 'ended_at' => now()]);
            $this->logEvent($session->id, 'EXAM_TIMEOUT', 'system', $run->id_candidate);
            throw new \Exception('Tempo esame terminato');
        }
    }

    private function ensureCurrentGroupIsValid(ExamSessionCandidateRun $run, ExamSession $session): ExamSessionCandidateRun
    {
        if ($run->status !== 'in_progress' || !$run->current_exam_area_id || !$run->current_exam_level_id) {
            return $run;
        }

        $rule = ExamExtractionRule::where('exam_area_id', $run->current_exam_area_id)
            ->where('exam_level_id', $run->current_exam_level_id)
            ->first();

        if (!$rule || !$run->current_step_started_at) {
            return $run;
        }

        $levelEndsAt = Carbon::parse($run->current_step_started_at)->addMinutes($rule->duration_minutes);

        if (now()->gt($levelEndsAt)) {
            $this->finalizeCurrentGroupAndAdvance($run, $session, 'timeout');
            $run->refresh();
        }

        return $run;
    }

    private function finalizeCurrentGroupAndAdvance(
        ExamSessionCandidateRun $run,
        ExamSession $session,
        string $reason
    ): void {
        $result = $this->computeGroupResult(
            $session->id,
            $run->id_candidate,
            $run->current_exam_area_id,
            $run->current_exam_level_id
        );

        $this->logEvent(
            $session->id,
            'LEVEL_FINISHED',
            'system',
            $run->id_candidate,
            [
                'exam_area_id' => $run->current_exam_area_id,
                'exam_level_id' => $run->current_exam_level_id,
                'reason' => $reason,
                'correct' => $result['correct'],
                'total' => $result['total'],
                'passed' => $result['passed'],
            ]
        );

        $next = $this->resolveNextGroup(
            $session->id_exam,
            $run->current_exam_area_id,
            $run->current_exam_level_id,
            $result['passed']
        );

        if ($next === null) {
            $run->update([
                'status' => 'completed',
                'ended_at' => now(),
                'current_exam_area_id' => null,
                'current_exam_level_id' => null,
            ]);
            $this->logEvent($session->id, 'EXAM_COMPLETED', 'system', $run->id_candidate);
            return;
        }

        $run->update([
            'current_exam_area_id' => $next['area']->id,
            'current_exam_level_id' => $next['level']->id,
            'current_step_started_at' => now(),
            'current_step' => ($run->current_step ?? 0) + 1,
        ]);

        $this->logEvent(
            $session->id,
            'LEVEL_STARTED',
            'system',
            $run->id_candidate,
            ['exam_area_id' => $next['area']->id, 'exam_level_id' => $next['level']->id]
        );
    }
}

