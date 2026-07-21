<?php

namespace App\Services;

use App\Models\Answer;
use App\Models\Candidate;
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
use App\Models\ExamFinished;
use App\Models\ExamFinishedArea;
use App\Models\ExamFinishedLevel;
use App\Models\ExamFinishedQuestion;
use App\Models\ExamFinishedQuestionOption;

class ExamEngineService
{
    // =========================================================
    // START SESSION
    // =========================================================
    public function startSession(string $plannedExamPublicId, ?array $requestMeta = null): ExamSession
    {
        return DB::transaction(function () use ($plannedExamPublicId, $requestMeta) {

            $plannedExam = PlannedExam::where('public_id', $plannedExamPublicId)->firstOrFail();
            $exam        = Exam::find($plannedExam->id_exam);

            $datePart = $plannedExam->date instanceof \Carbon\Carbon
                ? $plannedExam->date->format('Y-m-d')
                : (string) $plannedExam->date;

            $timePart = $plannedExam->time instanceof \Carbon\Carbon
                ? $plannedExam->time->format('H:i:s')
                : (string) $plannedExam->time;

            $examDateTime  = Carbon::parse($datePart . ' ' . $timePart);
            $allowedStart  = $examDateTime->copy()->subMinutes(10);

            if (now()->lt($allowedStart)) {
                Log::warning('startSession: tentativo di apertura sessione troppo anticipato', [
                    'planned_exam_public_id' => $plannedExamPublicId,
                    'allowed_start'          => $allowedStart->toIso8601String(),
                    'now'                    => now()->toIso8601String(),
                ]);
                throw new \Exception('La sessione può essere aperta solo 10 minuti prima dell\'esame');
            }

            $alreadyLive = ExamSession::where('id_planned_exam', $plannedExam->id)
                ->where('status', 'live')
                ->exists();

            if ($alreadyLive) {
                Log::warning('startSession: tentativo di apertura di una seconda sessione live', [
                    'planned_exam_id' => $plannedExam->id,
                ]);
                throw new \Exception('Esiste già una sessione attiva');
            }

            $candidates = PlannedExamCandidate::where('id_planned_exam', $plannedExam->id)->get();

            $session = ExamSession::create([
                'id_planned_exam' => $plannedExam->id,
                'id_exam'         => $plannedExam->id_exam,
                'status'          => 'live',
                'started_at'      => now(),
            ]);

            $this->logEvent(
                $session->id,
                'SESSION_STARTED',
                'system',
                null,
                $this->withMeta([
                    'planned_exam_id'      => $plannedExam->id,
                    'exam_id'              => $exam->id ?? null,
                    'exam_name'            => $exam->name ?? null,
                    'exam_duration_minutes'=> $exam->duration_minutes ?? null,
                    'scheduled_date'       => $datePart,
                    'scheduled_time'       => $timePart,
                    'total_candidates'     => $candidates->count(),
                ], $requestMeta),
                null // evento di sessione, non appartiene a un candidato specifico
            );

            foreach ($candidates as $candidate) {
                $run = ExamSessionCandidateRun::create([
                    'id_exam_session' => $session->id,
                    'id_candidate'    => $candidate->id_candidate,
                    'status'          => 'pending',
                    'current_step'    => 1,
                ]);

                $questions = $this->generateQuestionsForExam($plannedExam->id_exam);

                foreach ($questions as $index => $question) {
                    ExamSessionCandidateQuestion::create([
                        'id_candidate_run' => $run->id,
                        'id_question'      => $question->id,
                        'position'         => $index + 1,
                    ]);
                }

                $groupsBreakdown = $questions
                    ->groupBy(fn ($q) => $q->exam_area_id . '-' . $q->exam_level_id)
                    ->map(fn ($group) => [
                        'exam_area_id'  => $group->first()->exam_area_id,
                        'exam_level_id' => $group->first()->exam_level_id,
                        'count'         => $group->count(),
                    ])
                    ->values();

                $this->logEvent(
                    $session->id,
                    'QUESTIONS_ASSIGNED',
                    'candidate',
                    $candidate->id_candidate,
                    [
                        'questions_count' => count($questions),
                        'question_ids'    => $questions->pluck('id')->values(),
                        'groups'          => $groupsBreakdown,
                    ],
                    $candidate->id_candidate
                );

                $firstGroup = $this->resolveFirstGroup($plannedExam->id_exam);

                if ($firstGroup === null) {
                    Log::error('startSession: esame senza regole di estrazione configurate', [
                        'exam_id'      => $plannedExam->id_exam,
                        'candidate_id' => $candidate->id_candidate,
                    ]);
                    throw new \Exception('Esame non configurato: nessuna area/livello con regole di estrazione');
                }

                $run->update([
                    'current_exam_area_id'  => $firstGroup['area']->id,
                    'current_exam_level_id' => $firstGroup['level']->id,
                    'current_step_started_at' => now(),
                ]);

                $this->logEvent(
                    $session->id,
                    'LEVEL_STARTED',
                    'system',
                    $candidate->id_candidate,
                    $this->buildLevelStartedPayload($firstGroup['area'], $firstGroup['level']),
                    $candidate->id_candidate
                );
            }

            $session->refresh();

            // Notifica i candidati che la sessione è aperta
            $this->safeBroadcast(
                $session->id,
                new \App\Events\ExamSessionActivated($plannedExam->public_id, $session->public_id),
                'session.activated',
                "planned-exam.{$plannedExam->public_id}"
            );

            // Mostra subito all'esaminatore tutti i candidati in stato pending
            $this->broadcastRunsUpdate($session);

            return $session;
        });
    }

    // =========================================================
    // ENABLE CANDIDATE
    // =========================================================
    public function enableCandidate(
        string $sessionPublicId,
        int $candidateId,
        int $examinerId,
        ?array $requestMeta = null
    ): void {
        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

        $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)
            ->firstOrFail();

        $run->update(['status' => 'authorized']);

        $this->logEvent(
            $session->id,
            'CANDIDATE_AUTHORIZED',
            'examiner',
            $examinerId,
            $this->withMeta([
                'candidate_id'  => $candidateId,
                'authorized_at' => now()->toIso8601String(),
            ], $requestMeta),
            $candidateId
        );

        // Notifica in tempo reale sia l'esaminatore che il candidato
        $this->broadcastRunsUpdate($session);
    }

    // =========================================================
    // CANDIDATE JOINED
    // =========================================================
    public function candidateJoined(
        string $sessionPublicId,
        int $candidateId
    ): array {
        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

        $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)
            ->firstOrFail();

        if ($run->status === 'pending') {
            $run->update(['status' => 'waiting']);

            $this->logEvent(
                $session->id,
                'CANDIDATE_JOINED_SESSION',
                'candidate',
                $candidateId,
                ['joined_at' => now()->toIso8601String()],
                $candidateId
            );

            $this->broadcastRunsUpdate($session);
        }

        return [
            'session_status' => $session->status,
            'run_status'      => $run->status,
        ];
    }

    // =========================================================
    // HEARTBEAT (liveness candidato)
    // =========================================================
    public function heartbeat(string $sessionPublicId, int $candidateId): void
    {
        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

        $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)
            ->first();

        if (!$run || !in_array($run->status, ['waiting', 'authorized', 'in_progress'])) {
            Log::warning('heartbeat: ricevuto heartbeat per run assente o in stato non valido', [
                'session_public_id' => $sessionPublicId,
                'candidate_id'      => $candidateId,
                'run_status'        => $run->status ?? null,
            ]);
            return;
        }

        $run->update(['last_heartbeat_at' => now()]);
    }

    // =========================================================
    // DISCONNESSIONE CANDIDATO (self-report o rilevata dallo sweep)
    // =========================================================
    public function candidateDisconnected(
        string $sessionPublicId,
        int $candidateId,
        ?array $requestMeta = null
    ): void {
        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

        $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)
            ->first();

        if (!$run || in_array($run->status, ['completed', 'timeout', 'terminated', 'pending'])) {
            Log::warning('candidateDisconnected: notifica ignorata, run assente o già in stato finale/pending', [
                'session_public_id' => $sessionPublicId,
                'candidate_id'      => $candidateId,
                'run_status'        => $run->status ?? null,
            ]);
            return;
        }

        $run->update(['status' => 'terminated', 'ended_at' => now()]);

        $this->logEvent(
            $session->id,
            'CANDIDATE_DISCONNECTED',
            'candidate',
            $candidateId,
            $this->withMeta(['detected_at' => now()->toIso8601String()], $requestMeta),
            $candidateId
        );

        $this->maybeGenerateExamFinishedSnapshot($run, $session);

        $this->broadcastRunsUpdate($session);
    }

    // =========================================================
    // SWEEP PERIODICO — termina run senza heartbeat da troppo tempo
    // =========================================================
    public function terminateStaleConnections(int $timeoutSeconds = 45): int
    {
        $staleRuns = ExamSessionCandidateRun::whereIn('status', ['waiting', 'authorized', 'in_progress'])
            ->whereNotNull('last_heartbeat_at')
            ->where('last_heartbeat_at', '<', now()->subSeconds($timeoutSeconds))
            ->get();

        $count = 0;

        foreach ($staleRuns as $run) {
            $session = ExamSession::find($run->id_exam_session);
            if (!$session || $session->status !== 'live') continue;

            $run->update(['status' => 'terminated', 'ended_at' => now()]);

            Log::warning('terminateStaleConnections: run terminato per timeout heartbeat', [
                'exam_session_id'   => $session->id,
                'candidate_id'      => $run->id_candidate,
                'last_heartbeat_at' => $run->last_heartbeat_at?->toIso8601String(),
                'timeout_seconds'   => $timeoutSeconds,
            ]);

            $this->logEvent(
                $session->id,
                'CANDIDATE_DISCONNECTED',
                'system',
                $run->id_candidate,
                [
                    'reason'            => 'heartbeat_timeout',
                    'last_heartbeat_at' => $run->last_heartbeat_at?->toIso8601String(),
                ],
                $run->id_candidate
            );

            $this->maybeGenerateExamFinishedSnapshot($run, $session);

            $this->broadcastRunsUpdate($session);
            $count++;
        }

        return $count;
    }

    // =========================================================
    // TERMINATE CANDIDATE (azione manuale dell'esaminatore)
    // =========================================================
    public function terminateCandidate(
        string $sessionPublicId,
        int $candidateId,
        string $reason,
        ?array $requestMeta = null
    ): void {
        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

        $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)
            ->firstOrFail();

        if (in_array($run->status, ['completed', 'timeout', 'terminated'])) {
            Log::warning('terminateCandidate: tentativo di terminare un run già in stato finale', [
                'session_public_id' => $sessionPublicId,
                'candidate_id'      => $candidateId,
                'run_status'        => $run->status,
            ]);
            return;
        }

        $run->update([
            'status'   => 'terminated',
            'ended_at' => now(),
        ]);

        $this->logEvent(
            $session->id,
            'CANDIDATE_TERMINATED',
            'examiner',
            $candidateId,
            $this->withMeta([
                'reason'        => $reason,
                'terminated_at' => now()->toIso8601String(),
            ], $requestMeta),
            $candidateId
        );

        $this->maybeGenerateExamFinishedSnapshot($run, $session);

        $this->broadcastRunsUpdate($session);
    }

    // =========================================================
    // GET CANDIDATE EXAM
    // =========================================================
    public function getCandidateExam(
        string $sessionPublicId,
        int $candidateId,
        ?array $requestMeta = null
    ): array {
        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

        if ($session->status !== 'live') {
            Log::warning('getCandidateExam: accesso a sessione non live', [
                'session_public_id' => $sessionPublicId,
                'session_status'    => $session->status,
                'candidate_id'      => $candidateId,
            ]);
            throw new \Exception('La sessione non è attiva');
        }

        $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)
            ->firstOrFail();

        if (!in_array($run->status, ['authorized', 'in_progress', 'completed', 'timeout'])) {
            Log::warning('getCandidateExam: candidato non autorizzato ad accedere', [
                'session_public_id' => $sessionPublicId,
                'candidate_id'      => $candidateId,
                'run_status'        => $run->status,
            ]);
            throw new \Exception('Candidato non autorizzato');
        }

        $exam = Exam::find($session->id_exam);

        if ($run->status === 'authorized') {
            $run->update([
                'status'     => 'in_progress',
                'started_at' => now(),
            ]);

            $this->logEvent(
                $session->id,
                'CANDIDATE_STARTED_EXAM',
                'candidate',
                $candidateId,
                $this->withMeta([
                    'started_at'             => now()->toIso8601String(),
                    'exam_duration_minutes'  => $exam->duration_minutes ?? null,
                    'exam_ends_at'           => $exam && $exam->duration_minutes
                        ? now()->copy()->addMinutes($exam->duration_minutes)->toIso8601String()
                        : null,
                ], $requestMeta),
                $candidateId
            );

            // Notifica l'esaminatore che il candidato ha iniziato l'esame
            $this->broadcastRunsUpdate($session);
        }

        if (in_array($run->status, ['completed', 'timeout'])) {
            return $this->buildCompletedPayload($session, $run);
        }

        $this->ensureGlobalTimeNotExpired($session, $run);
        $run = $this->ensureCurrentGroupIsValid($run, $session);

        if ($run->status === 'completed') {
            return $this->buildCompletedPayload($session, $run);
        }

        $questions = ExamSessionCandidateQuestion::with(['question.answers'])
            ->where('id_candidate_run', $run->id)
            ->whereHas('question', function ($q) use ($run) {
                $q->where('exam_area_id', $run->current_exam_area_id)
                    ->where('exam_level_id', $run->current_exam_level_id);
            })
            ->orderBy('position')
            ->get();

        if ($questions->isEmpty()) {
            Log::warning('getCandidateExam: nessuna domanda trovata per il gruppo corrente', [
                'session_public_id' => $sessionPublicId,
                'candidate_id'      => $candidateId,
                'exam_area_id'      => $run->current_exam_area_id,
                'exam_level_id'     => $run->current_exam_level_id,
            ]);
        }

        // Ordine risposte pseudo-casuale ma stabile per (run, domanda)
        $questions->each(function ($cq) use ($run) {
            $cq->question->answers = $cq->question->answers
                ->sortBy(fn ($answer) => md5($run->id . '-' . $answer->id))
                ->values();

            $cq->question->answers->each(function ($answer) {
                $answer->makeHidden('is_correct');
            });
        });

        $rule = ExamExtractionRule::where('exam_area_id', $run->current_exam_area_id)
            ->where('exam_level_id', $run->current_exam_level_id)
            ->first();

        if (!$rule) {
            Log::error('getCandidateExam: regola di estrazione mancante per il gruppo corrente', [
                'session_public_id' => $sessionPublicId,
                'candidate_id'      => $candidateId,
                'exam_area_id'      => $run->current_exam_area_id,
                'exam_level_id'     => $run->current_exam_level_id,
            ]);
        }

        return [
            'session'       => $session,
            'run'           => $run,
            'exam_completed'=> false,
            'current_area'  => ExamArea::find($run->current_exam_area_id),
            'current_level' => ExamLevel::find($run->current_exam_level_id),
            'level_ends_at' => ($rule && $run->level_started_by_candidate_at)
                ? Carbon::parse($run->level_started_by_candidate_at)->addMinutes($rule->duration_minutes)
                : null,
            'exam_ends_at'  => $run->started_at
                ? Carbon::parse($run->started_at)->addMinutes($exam->duration_minutes ?? 60)
                : null,
            'questions'     => $questions,
        ];
    }

    // =========================================================
    // SUBMIT ANSWER
    // =========================================================
    public function submitAnswer(
        string $sessionPublicId,
        int $candidateId,
        int $questionId,
        array $answer,
        ?int $timeSpentSeconds = null,
        ?array $requestMeta = null
    ): ExamSessionAnswer {
        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

        if ($session->status !== 'live') {
            Log::warning('submitAnswer: tentativo di risposta su sessione chiusa', [
                'session_public_id' => $sessionPublicId,
                'candidate_id'      => $candidateId,
                'question_id'       => $questionId,
            ]);
            throw new \Exception('La sessione è chiusa');
        }

        $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)
            ->firstOrFail();

        if ($run->status !== 'in_progress') {
            Log::warning('submitAnswer: tentativo di risposta con run non in_progress', [
                'session_public_id' => $sessionPublicId,
                'candidate_id'      => $candidateId,
                'run_status'        => $run->status,
            ]);
            throw new \Exception('Esame non attivo per il candidato');
        }

        $this->ensureGlobalTimeNotExpired($session, $run);
        $run = $this->ensureCurrentGroupIsValid($run, $session);

        if ($run->status === 'completed') {
            Log::warning('submitAnswer: tentativo di risposta dopo finalizzazione del run', [
                'session_public_id' => $sessionPublicId,
                'candidate_id'      => $candidateId,
                'question_id'       => $questionId,
            ]);
            throw new \Exception('Esame concluso, non sono ammesse altre risposte');
        }

        $question = Question::find($questionId);

        if (
            !$question ||
            (int) $question->exam_area_id !== (int) $run->current_exam_area_id ||
            (int) $question->exam_level_id !== (int) $run->current_exam_level_id
        ) {
            Log::warning('submitAnswer: accesso non autorizzato a domanda fuori dal livello corrente', [
                'session_public_id' => $sessionPublicId,
                'candidate_id'      => $candidateId,
                'question_id'       => $questionId,
            ]);
            $this->logEvent(
                $session->id,
                'UNAUTHORIZED_QUESTION_ACCESS',
                'candidate',
                $candidateId,
                $this->withMeta(['question_id' => $questionId, 'reason' => 'fuori dal livello corrente'], $requestMeta),
                $candidateId
            );
            throw new \Exception('Domanda non appartenente al livello corrente');
        }

        $assignedQuestion = ExamSessionCandidateQuestion::where('id_question', $questionId)
            ->where('id_candidate_run', $run->id)
            ->exists();

        if (!$assignedQuestion) {
            Log::warning('submitAnswer: accesso non autorizzato a domanda non assegnata', [
                'session_public_id' => $sessionPublicId,
                'candidate_id'      => $candidateId,
                'question_id'       => $questionId,
            ]);
            $this->logEvent(
                $session->id,
                'UNAUTHORIZED_QUESTION_ACCESS',
                'candidate',
                $candidateId,
                $this->withMeta(['question_id' => $questionId], $requestMeta),
                $candidateId
            );
            throw new \Exception('Domanda non assegnata');
        }

        $alreadyAnswered = ExamSessionAnswer::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)
            ->where('id_question', $questionId)
            ->exists();

        if ($alreadyAnswered) {
            Log::warning('submitAnswer: tentativo di risposta duplicata', [
                'session_public_id' => $sessionPublicId,
                'candidate_id'      => $candidateId,
                'question_id'       => $questionId,
            ]);
            $this->logEvent(
                $session->id,
                'DUPLICATE_ANSWER_ATTEMPT',
                'candidate',
                $candidateId,
                $this->withMeta(['question_id' => $questionId], $requestMeta),
                $candidateId
            );
            throw new \Exception('Domanda già risposta');
        }

        $correctAnswer = Answer::where('id_question', $questionId)
            ->where('is_correct', 'true')
            ->first();

        if (!$correctAnswer) {
            Log::error('submitAnswer: nessuna risposta corretta configurata per la domanda', [
                'question_id' => $questionId,
            ]);
        }

        $isCorrect = false;
        if ($correctAnswer) {
            $selectedAnswerId = $answer['answer_id'] ?? null;
            $isCorrect        = ($correctAnswer->id == $selectedAnswerId);
        }

        $savedAnswer = DB::transaction(function () use (
            $session, $candidateId, $questionId, $answer, $isCorrect, $timeSpentSeconds, $run, $requestMeta
        ) {
            try {
                $saved = ExamSessionAnswer::create([
                    'id_exam_session'    => $session->id,
                    'id_question'        => $questionId,
                    'id_candidate'       => $candidateId,
                    'answer'             => $answer,
                    'is_correct'         => $isCorrect,
                    'time_spent_seconds' => $timeSpentSeconds,
                    'id_exam_session_step' => $run->current_step,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->getCode() === '23505') {
                    Log::warning('submitAnswer: race condition su risposta duplicata intercettata a livello DB', [
                        'session_id'   => $session->id,
                        'candidate_id' => $candidateId,
                        'question_id'  => $questionId,
                    ]);
                    $this->logEvent(
                        $session->id, 'DUPLICATE_ANSWER_ATTEMPT', 'candidate', $candidateId,
                        ['question_id' => $questionId], $candidateId
                    );
                    throw new \Exception('Domanda già risposta');
                }

                Log::error('submitAnswer: errore query inatteso durante il salvataggio della risposta', [
                    'session_id'   => $session->id,
                    'candidate_id' => $candidateId,
                    'question_id'  => $questionId,
                    'error'        => $e->getMessage(),
                ]);
                throw $e;
            }

            $this->logEvent(
                $session->id,
                'ANSWER_SUBMITTED',
                'candidate',
                $candidateId,
                $this->withMeta([
                    'question_id'          => $questionId,
                    'is_correct'           => $isCorrect,
                    'time_spent_seconds'   => $timeSpentSeconds,
                    'id_exam_session_step' => $run->current_step,
                ], $requestMeta),
                $candidateId
            );

            return $saved;
        });

        $levelResult = null;

        $rule = ExamExtractionRule::where('exam_area_id', $run->current_exam_area_id)
            ->where('exam_level_id', $run->current_exam_level_id)
            ->first();

        if (!$rule) {
            Log::error('submitAnswer: regola di estrazione mancante, impossibile valutare avanzamento livello', [
                'session_id'    => $session->id,
                'candidate_id'  => $candidateId,
                'exam_area_id'  => $run->current_exam_area_id,
                'exam_level_id' => $run->current_exam_level_id,
            ]);
        }

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
                $levelResult = $this->finalizeCurrentGroupAndAdvance($run, $session, 'completed');
            }
        }

        $savedAnswer->makeHidden('is_correct');

        if ($levelResult !== null) {
            $savedAnswer->level_result = [
                'correct' => $levelResult['correct'],
                'total'   => $levelResult['total'],
                'passed'  => $levelResult['passed'],
            ];
        }

        return $savedAnswer;
    }

    // =========================================================
    // GET ACTIVITY LOG
    // =========================================================
    public function getActivityLog(
        string $sessionPublicId,
        int $candidateId,
        bool $includeSensitiveDetails = false
    ): array {
        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

        $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)
            ->firstOrFail();

        // Filtro su id_candidate (colonna dedicata) invece di actor_id: actor_id
        // rappresenta "chi ha generato il log" (può essere l'esaminatore), mentre
        // id_candidate rappresenta sempre "a quale candidato appartiene il log".
        $events = ExamSessionLog::where('id_exam_session', $session->id)
            ->where(function ($q) use ($candidateId) {
                $q->where('id_candidate', $candidateId)->orWhereNull('id_candidate');
            })
            ->orderBy('id')
            ->get()
            ->map(function ($log) use ($includeSensitiveDetails) {
                $payload = $log->payload;

                if (!$includeSensitiveDetails && is_array($payload) && array_key_exists('is_correct', $payload)) {
                    unset($payload['is_correct']);
                }

                return [
                    'id'         => $log->id,
                    'event_type' => $log->event_type,
                    'actor_type' => $log->actor_type,
                    'actor_id'   => $log->actor_id,
                    'payload'    => $payload,
                    'created_at' => $log->created_at,
                ];
            })
            ->values();

        return [
            'session' => [
                'public_id'  => $session->public_id,
                'status'     => $session->status,
                'started_at' => $session->started_at,
                'ended_at'   => $session->ended_at,
            ],
            'run' => [
                'status'               => $run->status,
                'started_at'           => $run->started_at,
                'ended_at'             => $run->ended_at,
                'current_step'         => $run->current_step,
                'current_exam_area_id' => $run->current_exam_area_id,
                'current_exam_level_id'=> $run->current_exam_level_id,
            ],
            'events' => $events,
        ];
    }

    // =========================================================
    // CALCULATE SCORE
    // =========================================================
// =========================================================
    // CALCULATE SCORE
    // =========================================================
// =========================================================
    // CALCULATE SCORE
    // =========================================================
    public function calculateScore(
        string $sessionPublicId,
        int $candidateId,
        ?array $requestMeta = null
    ): array {
        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

        $report = $this->buildScoreReport($session->id_exam, $session->id, $candidateId);

        $this->logEvent(
            $session->id,
            'REPORT_GENERATED',
            'system',
            $candidateId,
            $this->withMeta(['report' => $report], $requestMeta),
            $candidateId
        );

        // Lo snapshot potrebbe non esistere ancora se lo score viene
        // interrogato prima che il run raggiunga uno stato finale (raro,
        // ma possibile se il frontend chiama /score in un momento sbagliato),
        // o se la generazione era fallita silenziosamente in passato.
        $examFinishedPublicId = ExamFinished::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)
            ->value('public_id');

        if (!$examFinishedPublicId) {
            Log::warning('calculateScore: nessuno snapshot exam_finished trovato per il candidato', [
                'session_id'   => $session->id,
                'candidate_id' => $candidateId,
            ]);
        }

        return [
            'areas'                    => $report,
            'exam_finished_public_id'  => $examFinishedPublicId,
        ];
    }

    /**
     * Logica di attestazione condivisa tra calculateScore() (chiamato a
     * richiesta) e la generazione dello snapshot exam_finished (chiamata
     * una sola volta alla chiusura del run). Stessa regola in entrambi i
     * casi: un'area affrontata non può restare "non classificata", viene
     * attestata al primo livello se nessuno è stato superato.
     */
    private function buildScoreReport(int $examId, int $sessionId, int $candidateId): array
    {
        $areas  = $this->getOrderedAreasWithRules($examId);

        if ($areas->isEmpty()) {
            Log::error('buildScoreReport: nessuna area con regole di estrazione trovata per l\'esame', [
                'exam_id'      => $examId,
                'session_id'   => $sessionId,
                'candidate_id' => $candidateId,
            ]);
        }

        $report = [];

        foreach ($areas as $area) {
            $levels               = $this->getOrderedLevelsForArea($area->id);
            $highestPassed        = null;
            $firstLevel            = $levels->first();
            $firstLevelAttempted  = false;

            foreach ($levels as $level) {
                $result = $this->computeGroupResult($sessionId, $candidateId, $area->id, $level->id);

                if ($firstLevel && $level->id === $firstLevel->id) {
                    $firstLevelAttempted = $result['total'] > 0;
                }

                if ($result['total'] === 0) continue;
                if ($result['passed']) $highestPassed = $level;
            }

            $certifiedLevel = $highestPassed ?? ($firstLevelAttempted ? $firstLevel : null);

            $report[] = [
                'exam_area_id'        => $area->id,
                'area_name'           => $area->name,
                'area_label'          => $area->label,
                'highest_level_passed'=> $certifiedLevel ? [
                    'exam_level_id' => $certifiedLevel->id,
                    'name'          => $certifiedLevel->name,
                    'label'         => $certifiedLevel->label,
                    'order'         => $certifiedLevel->order,
                ] : null,
            ];
        }

        return $report;
    }
    // =========================================================
    // EXAM FINISHED — snapshot immutabile al termine del run
    // =========================================================

    /**
     * Punto d'ingresso unico. Va chiamato subito dopo ogni update che porta
     * run.status a uno stato finale (completed | timeout | terminated),
     * da qualsiasi dei sei percorsi che possono chiudere un run. Idempotente:
     * se lo snapshot esiste già per (session, candidate) non fa nulla — non
     * deve mai essere rigenerato/sovrascritto.
     */
    private function maybeGenerateExamFinishedSnapshot(
        ExamSessionCandidateRun $run,
        ExamSession $session
    ): void {
        if (!in_array($run->status, ['completed', 'timeout', 'terminated'])) {
            return;
        }

        $exists = ExamFinished::where('id_exam_session', $session->id)
            ->where('id_candidate', $run->id_candidate)
            ->exists();

        if ($exists) {
            Log::warning('maybeGenerateExamFinishedSnapshot: snapshot già presente, generazione ignorata', [
                'session_id'   => $session->id,
                'candidate_id' => $run->id_candidate,
            ]);
            return;
        }

        // Un eventuale fallimento qui non deve bloccare la chiusura del run:
        // la logica di business (stato finale, log, broadcast) è già stata
        // completata da chi ha chiamato questo metodo. Stesso principio di
        // safeBroadcast: si logga l'errore, non si propaga.
        try {
            DB::transaction(function () use ($run, $session) {
                $this->buildExamFinishedSnapshot($run, $session);
            });
        } catch (\Throwable $e) {
            Log::error('maybeGenerateExamFinishedSnapshot: generazione snapshot fallita', [
                'session_id'   => $session->id,
                'candidate_id' => $run->id_candidate,
                'run_status'   => $run->status,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    private function buildExamFinishedSnapshot(
        ExamSessionCandidateRun $run,
        ExamSession $session
    ): void {
        $exam = Exam::find($session->id_exam);

        $totalDurationSeconds = $run->started_at
            ? (int) round(Carbon::parse($run->started_at)->diffInSeconds($run->ended_at ?? now()))
            : null;

        $reportSnapshot = $this->buildScoreReport($session->id_exam, $session->id, $run->id_candidate);

        $examFinished = ExamFinished::create([
            'id_exam_session'                => $session->id,
            'id_exam'                        => $session->id_exam,
            'id_candidate'                   => $run->id_candidate,
            'exam_name_snapshot'             => $exam->name ?? null,
            'exam_duration_minutes_snapshot' => $exam->duration_minutes ?? null,
            'session_status'                 => $session->status,
            'run_status'                     => $run->status,
            'started_at'                     => $run->started_at,
            'ended_at'                       => $run->ended_at,
            'total_duration_seconds'         => $totalDurationSeconds,
            'report_snapshot'                => $reportSnapshot,
            'generated_at'                   => now(),
        ]);

        $this->buildAreasSnapshot($examFinished, $run, $session);
    }

    private function buildAreasSnapshot(
        ExamFinished $examFinished,
        ExamSessionCandidateRun $run,
        ExamSession $session
    ): void {
        $areas = $this->getOrderedAreasWithRules($session->id_exam);

        // Percorso dei gruppi conclusi regolarmente, così come già loggati
        // da finalizeCurrentGroupAndAdvance durante l'esame.
        $finishedLevelLogs = ExamSessionLog::where('id_exam_session', $session->id)
            ->where('id_candidate', $run->id_candidate)
            ->where('event_type', 'LEVEL_FINISHED')
            ->orderBy('id')
            ->get();

        if ($finishedLevelLogs->isEmpty()) {
            Log::warning('buildAreasSnapshot: nessun log LEVEL_FINISHED trovato per il candidato', [
                'session_id'   => $session->id,
                'candidate_id' => $run->id_candidate,
            ]);
        }

        foreach ($areas as $area) {
            $levels              = $this->getOrderedLevelsForArea($area->id);
            $highestPassed       = null;
            $firstLevel           = $levels->first();
            $firstLevelAttempted = false;
            $anyAttempted        = false;

            foreach ($levels as $level) {
                $result = $this->computeGroupResult($session->id, $run->id_candidate, $area->id, $level->id);

                if ($firstLevel && $level->id === $firstLevel->id) {
                    $firstLevelAttempted = $result['total'] > 0;
                }
                if ($result['total'] > 0) {
                    $anyAttempted = true;
                    if ($result['passed']) $highestPassed = $level;
                }
            }

            $certifiedLevel = $highestPassed ?? ($firstLevelAttempted ? $firstLevel : null);

            $areaStatus = $highestPassed
                ? 'passed'
                : ($anyAttempted ? 'failed_or_skipped' : 'not_reached');

            $certifiedResult = $certifiedLevel
                ? $this->computeGroupResult($session->id, $run->id_candidate, $area->id, $certifiedLevel->id)
                : null;

            $finishedArea = ExamFinishedArea::create([
                'id_exam_finished'               => $examFinished->id,
                'id_exam_area'                   => $area->id,
                'area_name_snapshot'             => $area->name,
                'area_label_snapshot'            => $area->label,
                'area_order_snapshot'            => $area->order,
                'area_status'                    => $areaStatus,
                'id_exam_level_certified'        => $certifiedLevel->id ?? null,
                'level_certified_name_snapshot'  => $certifiedLevel->name ?? null,
                'level_certified_label_snapshot' => $certifiedLevel->label ?? null,
                'level_certified_order_snapshot' => $certifiedLevel->order ?? null,
                'correct'                        => $certifiedResult['correct'] ?? null,
                'total'                          => $certifiedResult['total'] ?? null,
            ]);

            $areaFinishedLogs = $finishedLevelLogs->filter(
                fn ($log) => (int) ($log->payload['exam_area_id'] ?? 0) === (int) $area->id
            )->values();

            foreach ($areaFinishedLogs as $log) {
                $this->createFinishedLevelFromLog($examFinished, $finishedArea, $run, $log);
            }

            // Il gruppo corrente del run appartiene a quest'area e non è mai
            // stato finalizzato (run chiuso da un percorso esterno mentre il
            // candidato era ancora dentro questo livello): lo registro come
            // tentativo interrotto, per non perdere la foto reale.
            $currentBelongsToThisArea = (int) $run->current_exam_area_id === (int) $area->id;
            $alreadyLogged = $areaFinishedLogs->contains(
                fn ($log) => (int) ($log->payload['exam_level_id'] ?? 0) === (int) $run->current_exam_level_id
            );

            if ($currentBelongsToThisArea && $run->current_exam_level_id && !$alreadyLogged) {
                $this->appendIncompleteLevel($examFinished, $finishedArea, $run, $session);
            }
        }
    }

    private function createFinishedLevelFromLog(
        ExamFinished $examFinished,
        ExamFinishedArea $finishedArea,
        ExamSessionCandidateRun $run,
        ExamSessionLog $log
    ): void {
        $payload    = $log->payload ?? [];
        $levelId    = $payload['exam_level_id'] ?? null;
        $level      = $levelId ? ExamLevel::find($levelId) : null;

        if (!$level) {
            Log::warning('createFinishedLevelFromLog: livello del log LEVEL_FINISHED non trovato', [
                'log_id'        => $log->id,
                'exam_level_id' => $levelId,
            ]);
        }

        $finishedLevel = ExamFinishedLevel::create([
            'id_exam_finished'                => $examFinished->id,
            'id_exam_finished_area'           => $finishedArea->id,
            'id_exam_level'                   => $levelId,
            'level_name_snapshot'             => $payload['level_name'] ?? $level->name ?? null,
            'level_order_snapshot'            => $level->order ?? null,
            'rule_n_questions_snapshot'       => $payload['n_questions_required'] ?? null,
            'rule_duration_minutes_snapshot'  => $payload['level_duration_minutes'] ?? null,
            'rule_passing_score_snapshot'     => $payload['passing_score_required'] ?? null,
            'correct'                         => $payload['correct'] ?? null,
            'total'                           => $payload['total'] ?? null,
            'passed'                          => $payload['passed'] ?? null,
            'reason'                          => $payload['reason'] ?? 'completed',
            'is_final_incomplete_level'       => false,
            'duration_used_seconds'           => isset($payload['duration_used_seconds'])
                ? (int) round($payload['duration_used_seconds'])
                : null,
        ]);

        $this->buildQuestionsSnapshot($finishedLevel, $run, (int) $run->current_exam_area_id === (int) $finishedArea->id_exam_area
            ? (int) $run->current_exam_area_id
            : (int) $finishedArea->id_exam_area, (int) $levelId);
    }

    private function appendIncompleteLevel(
        ExamFinished $examFinished,
        ExamFinishedArea $finishedArea,
        ExamSessionCandidateRun $run,
        ExamSession $session
    ): void {
        $level = ExamLevel::find($run->current_exam_level_id);
        $rule  = ExamExtractionRule::where('exam_area_id', $run->current_exam_area_id)
            ->where('exam_level_id', $run->current_exam_level_id)
            ->first();

        if (!$rule) {
            Log::error('appendIncompleteLevel: regola di estrazione mancante per il livello interrotto', [
                'session_id'    => $session->id,
                'candidate_id'  => $run->id_candidate,
                'exam_area_id'  => $run->current_exam_area_id,
                'exam_level_id' => $run->current_exam_level_id,
            ]);
        }

        $result = $this->computeGroupResult(
            $session->id, $run->id_candidate, $run->current_exam_area_id, $run->current_exam_level_id
        );

        $durationUsedSeconds = $run->current_step_started_at
            ? (int) round(Carbon::parse($run->current_step_started_at)->diffInSeconds($run->ended_at ?? now()))
            : null;

        $finishedLevel = ExamFinishedLevel::create([
            'id_exam_finished'                => $examFinished->id,
            'id_exam_finished_area'           => $finishedArea->id,
            'id_exam_level'                   => $run->current_exam_level_id,
            'level_name_snapshot'             => $level->name ?? null,
            'level_order_snapshot'            => $level->order ?? null,
            'rule_n_questions_snapshot'       => $rule->n_questions ?? null,
            'rule_duration_minutes_snapshot'  => $rule->duration_minutes ?? null,
            'rule_passing_score_snapshot'     => $rule->passing_score ?? null,
            'correct'                         => $result['total'] > 0 ? $result['correct'] : null,
            'total'                           => $result['total'],
            'passed'                          => null, // interrotto, non valutabile
            'reason'                          => 'terminated_mid_level',
            'is_final_incomplete_level'       => true,
            'duration_used_seconds'           => $durationUsedSeconds,
        ]);

        $this->buildQuestionsSnapshot(
            $finishedLevel, $run, (int) $run->current_exam_area_id, (int) $run->current_exam_level_id
        );
    }

    /**
     * Specchio di tutte le domande ASSEGNATE al candidato per quel gruppo
     * (risposte o no), con tutte le opzioni mostrate e quale ha selezionato.
     * NB: question_text_snapshot / answer_text_snapshot assumono le colonne
     * `text` su Question/Answer — da verificare contro lo schema reale.finalizeCurrentGroupAndAdvance
     */
    private function buildQuestionsSnapshot(
        ExamFinishedLevel $finishedLevel,
        ExamSessionCandidateRun $run,
        int $areaId,
        int $levelId
    ): void {
        $assignedQuestions = ExamSessionCandidateQuestion::with(['question.answers'])
            ->where('id_candidate_run', $run->id)
            ->whereHas('question', function ($q) use ($areaId, $levelId) {
                $q->where('exam_area_id', $areaId)->where('exam_level_id', $levelId);
            })
            ->orderBy('position')
            ->get();

        if ($assignedQuestions->isEmpty()) {
            Log::warning('buildQuestionsSnapshot: nessuna domanda assegnata trovata per il gruppo', [
                'id_candidate_run' => $run->id,
                'exam_area_id'     => $areaId,
                'exam_level_id'    => $levelId,
            ]);
            return;
        }

        foreach ($assignedQuestions as $cq) {
            $question = $cq->question;

            if (!$question) {
                Log::error('buildQuestionsSnapshot: domanda assegnata non trovata (cancellata?)', [
                    'id_candidate_question' => $cq->id,
                ]);
                continue;
            }

            $candidateAnswer = ExamSessionAnswer::where('id_exam_session', $run->id_exam_session)
                ->where('id_candidate', $run->id_candidate)
                ->where('id_question', $question->id)
                ->first();

            $selectedAnswerId = $candidateAnswer->answer['answer_id'] ?? null;

            $finishedQuestion = ExamFinishedQuestion::create([
                'id_exam_finished_level'  => $finishedLevel->id,
                'id_question'             => $question->id,
                'question_text_snapshot'  => $question->text,
                'position'                => $cq->position,
                'was_answered'            => $candidateAnswer !== null,
            ]);

            if ($question->answers->isEmpty()) {
                Log::warning('buildQuestionsSnapshot: domanda senza risposte configurate', [
                    'id_question' => $question->id,
                ]);
            }

            foreach ($question->answers as $index => $answer) {
                ExamFinishedQuestionOption::create([
                    'id_exam_finished_question'  => $finishedQuestion->id,
                    'id_answer'                  => $answer->id,
                    'answer_text_snapshot'       => $answer->text,
                    'is_correct_snapshot'        => $answer->is_correct === 'true',
                    'was_selected_by_candidate'  => $selectedAnswerId !== null
                        && (int) $selectedAnswerId === (int) $answer->id,
                    'display_order'              => $index + 1,
                ]);
            }
        }
    }
    // =========================================================
    // END SESSION
    // =========================================================
    public function endSession(
        string $sessionPublicId,
        ?array $requestMeta = null
    ): ExamSession {
        return DB::transaction(function () use ($sessionPublicId, $requestMeta) {

            $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

            if ($session->status !== 'live') {
                Log::warning('endSession: tentativo di chiudere una sessione non attiva', [
                    'session_public_id' => $sessionPublicId,
                    'session_status'    => $session->status,
                ]);
                throw new \Exception('Sessione non attiva');
            }

            $session->update([
                'status'   => 'completed',
                'ended_at' => now(),
            ]);

            $runsToClose = ExamSessionCandidateRun::where('id_exam_session', $session->id)
                ->whereIn('status', ['pending', 'waiting', 'authorized', 'in_progress'])
                ->get();

            foreach ($runsToClose as $runToClose) {
                $runToClose->update(['status' => 'completed', 'ended_at' => now()]);
                $this->maybeGenerateExamFinishedSnapshot($runToClose, $session);
            }

            $statusCounts = ExamSessionCandidateRun::where('id_exam_session', $session->id)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $this->logEvent(
                $session->id,
                'SESSION_ENDED',
                'system',
                null,
                $this->withMeta([
                    'candidates_by_status' => $statusCounts,
                    'total_candidates'     => $statusCounts->sum(),
                ], $requestMeta),
                null
            );

            // Notifica tutti i candidati ancora connessi
            $this->safeBroadcast(
                $session->id,
                new \App\Events\ExamSessionEnded($session->public_id),
                'session.ended',
                "exam-session.{$session->public_id}"
            );

            return $session;
        });
    }

    // =========================================================
    // QUESTION GENERATION
    // =========================================================
    private function generateQuestionsForExam(int $examId)
    {
        $rules = ExamExtractionRule::whereHas('area', function ($query) use ($examId) {
            $query->where('exam_id', $examId);
        })->get();

        if ($rules->isEmpty()) {
            Log::warning('generateQuestionsForExam: nessuna regola di estrazione trovata per l\'esame', [
                'exam_id' => $examId,
            ]);
        }

        $questions = collect();

        foreach ($rules as $rule) {
            $subset = Question::where('exam_id', $examId)
                ->where('exam_area_id', $rule->exam_area_id)
                ->where('exam_level_id', $rule->exam_level_id)
                ->inRandomOrder()
                ->limit($rule->n_questions)
                ->get();

            if ($subset->count() < $rule->n_questions) {
                Log::error('generateQuestionsForExam: domande insufficienti per area/livello', [
                    'exam_id'       => $examId,
                    'exam_area_id'  => $rule->exam_area_id,
                    'exam_level_id' => $rule->exam_level_id,
                    'required'      => $rule->n_questions,
                    'available'     => $subset->count(),
                ]);
                throw new \Exception(
                    "Domande insufficienti per area {$rule->exam_area_id} livello {$rule->exam_level_id}"
                );
            }

            $questions = $questions->merge($subset);
        }

        return $questions->shuffle()->values();
    }

    // =========================================================
    // AREA/LEVEL STATE MACHINE
    // =========================================================
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
            Log::warning('resolveFirstGroup: nessuna area configurata per l\'esame', ['exam_id' => $examId]);
            return null;
        }

        $levels = $this->getOrderedLevelsForArea($areas->first()->id);
        if ($levels->isEmpty()) {
            Log::warning('resolveFirstGroup: nessun livello configurato per la prima area', [
                'exam_id' => $examId,
                'exam_area_id' => $areas->first()->id,
            ]);
            return null;
        }

        return ['area' => $areas->first(), 'level' => $levels->first()];
    }

    private function resolveNextGroup(int $examId, int $currentAreaId, int $currentLevelId, bool $passed): ?array
    {
        if ($passed) {
            $levelsInArea  = $this->getOrderedLevelsForArea($currentAreaId);
            $currentIndex  = $levelsInArea->search(fn ($l) => (int) $l->id === (int) $currentLevelId);

            if ($currentIndex !== false && isset($levelsInArea[$currentIndex + 1])) {
                return [
                    'area'  => ExamArea::find($currentAreaId),
                    'level' => $levelsInArea[$currentIndex + 1],
                ];
            }
        }

        $areas      = $this->getOrderedAreasWithRules($examId);
        $areaIndex  = $areas->search(fn ($a) => (int) $a->id === (int) $currentAreaId);

        if ($areaIndex === false || !isset($areas[$areaIndex + 1])) {
            return null;
        }

        $nextArea          = $areas[$areaIndex + 1];
        $levelsInNextArea  = $this->getOrderedLevelsForArea($nextArea->id);

        if ($levelsInNextArea->isEmpty()) {
            Log::error('resolveNextGroup: area successiva senza regole di estrazione configurate', [
                'exam_id'      => $examId,
                'next_area_id' => $nextArea->id,
            ]);
            throw new \Exception("Area {$nextArea->id} non ha regole di estrazione configurate");
        }

        return ['area' => $nextArea, 'level' => $levelsInNextArea->first()];
    }

    private function computeGroupResult(int $sessionId, int $candidateId, int $areaId, int $levelId): array
    {
        $rule = ExamExtractionRule::where('exam_area_id', $areaId)
            ->where('exam_level_id', $levelId)
            ->first();

        if (!$rule) {
            Log::warning('computeGroupResult: regola di estrazione mancante per area/livello', [
                'session_id'    => $sessionId,
                'candidate_id'  => $candidateId,
                'exam_area_id'  => $areaId,
                'exam_level_id' => $levelId,
            ]);
        }

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
            'total'          => $total,
            'correct'        => $correct,
            'passing_score'  => $rule->passing_score ?? null,
            'expected_total' => $rule->n_questions ?? null,
            'passed'         => $rule !== null && $correct >= $rule->passing_score,
        ];
    }

    private function ensureGlobalTimeNotExpired(ExamSession $session, ExamSessionCandidateRun $run): void
    {
        if (!$run->started_at) return;

        $exam            = Exam::find($session->id_exam);
        $durationMinutes = $exam->duration_minutes ?? 60;
        $startedAt       = Carbon::parse($run->started_at);
        $endTime         = $startedAt->copy()->addMinutes($durationMinutes);

        if (now()->gt($endTime)) {
            $run->update(['status' => 'timeout', 'ended_at' => now()]);

            Log::warning('ensureGlobalTimeNotExpired: run terminato per timeout esame globale', [
                'session_id'    => $session->id,
                'candidate_id'  => $run->id_candidate,
                'started_at'    => $startedAt->toIso8601String(),
                'duration_min'  => $durationMinutes,
            ]);

            $this->logEvent($session->id, 'EXAM_TIMEOUT', 'system', $run->id_candidate, [
                'exam_area_id'          => $run->current_exam_area_id,
                'exam_level_id'         => $run->current_exam_level_id,
                'exam_duration_minutes' => $durationMinutes,
                'started_at'            => $startedAt->toIso8601String(),
                'timed_out_at'          => now()->toIso8601String(),
                'elapsed_seconds'       => $startedAt->diffInSeconds(now()),
            ], $run->id_candidate);

            $this->maybeGenerateExamFinishedSnapshot($run, $session);

            $this->broadcastRunsUpdate($session);

            throw new \Exception('Tempo esame terminato');
        }
    }

    private function ensureCurrentGroupIsValid(
        ExamSessionCandidateRun $run,
        ExamSession $session
    ): ExamSessionCandidateRun {
        if ($run->status !== 'in_progress' || !$run->current_exam_area_id || !$run->current_exam_level_id) {
            return $run;
        }

        // Se il candidato non ha ancora cliccato "Inizia il livello", non può
        // essere scaduto nulla — a prescindere da quanto tempo sia passato
        // dall'assegnazione del gruppo (LEVEL_STARTED, che è un evento di
        // sistema, non l'inizio effettivo del cronometro per il candidato).
        if (!$run->level_started_by_candidate_at) {
            return $run;
        }

        $rule = ExamExtractionRule::where('exam_area_id', $run->current_exam_area_id)
            ->where('exam_level_id', $run->current_exam_level_id)
            ->first();

        if (!$rule) {
            Log::error('ensureCurrentGroupIsValid: regola di estrazione mancante, impossibile valutare la scadenza del livello', [
                'session_id'    => $session->id,
                'candidate_id'  => $run->id_candidate,
                'exam_area_id'  => $run->current_exam_area_id,
                'exam_level_id' => $run->current_exam_level_id,
            ]);
            return $run;
        }

        $levelEndsAt = Carbon::parse($run->level_started_by_candidate_at)->addMinutes($rule->duration_minutes);

        if (now()->gt($levelEndsAt)) {
            Log::warning('ensureCurrentGroupIsValid: livello scaduto, finalizzazione forzata per timeout', [
                'session_id'    => $session->id,
                'candidate_id'  => $run->id_candidate,
                'exam_area_id'  => $run->current_exam_area_id,
                'exam_level_id' => $run->current_exam_level_id,
                'level_ends_at' => $levelEndsAt->toIso8601String(),
            ]);
            $this->finalizeCurrentGroupAndAdvance($run, $session, 'timeout');
            $run->refresh();
        }

        return $run;
    }

    private function buildLevelStartedPayload(ExamArea $area, ExamLevel $level): array
    {
        $rule = ExamExtractionRule::where('exam_area_id', $area->id)
            ->where('exam_level_id', $level->id)
            ->first();

        if (!$rule) {
            Log::warning('buildLevelStartedPayload: regola di estrazione mancante per area/livello', [
                'exam_area_id'  => $area->id,
                'exam_level_id' => $level->id,
            ]);
        }

        return [
            'exam_area_id'          => $area->id,
            'exam_level_id'         => $level->id,
            'area_name'             => $area->name,
            'area_label'            => $area->label,
            'level_name'            => $level->name,
            'level_label'           => $level->label,
            'level_duration_minutes'=> $rule->duration_minutes ?? null,
            'n_questions_required'  => $rule->n_questions ?? null,
            'passing_score_required'=> $rule->passing_score ?? null,
        ];
    }

    private function finalizeCurrentGroupAndAdvance(
        ExamSessionCandidateRun $run,
        ExamSession $session,
        string $reason
    ): array {
        $result         = $this->computeGroupResult($session->id, $run->id_candidate, $run->current_exam_area_id, $run->current_exam_level_id);
        $finishedArea   = ExamArea::find($run->current_exam_area_id);
        $finishedLevel  = ExamLevel::find($run->current_exam_level_id);
        $finishedRule   = ExamExtractionRule::where('exam_area_id', $run->current_exam_area_id)->where('exam_level_id', $run->current_exam_level_id)->first();
        $durationUsedSeconds = $run->current_step_started_at ? Carbon::parse($run->current_step_started_at)->diffInSeconds(now()) : null;

        $this->logEvent(
            $session->id,
            'LEVEL_FINISHED',
            'system',
            $run->id_candidate,
            [
                'exam_area_id'           => $run->current_exam_area_id,
                'exam_level_id'          => $run->current_exam_level_id,
                'area_name'              => $finishedArea->name ?? null,
                'level_name'             => $finishedLevel->name ?? null,
                'reason'                 => $reason,
                'correct'                => $result['correct'],
                'total'                  => $result['total'],
                'passed'                 => $result['passed'],
                'passing_score_required' => $finishedRule->passing_score ?? null,
                'level_duration_minutes' => $finishedRule->duration_minutes ?? null,
                'duration_used_seconds'  => $durationUsedSeconds,
            ],
            $run->id_candidate
        );

        $next = $this->resolveNextGroup($session->id_exam, $run->current_exam_area_id, $run->current_exam_level_id, $result['passed']);

        if ($next === null) {
            // Esame concluso: nessun gruppo successivo. Qui NON si tocca
            // current_exam_area_id/level — restano quelli dell'ultimo gruppo
            // affrontato, utili per referenza/audit. Solo lo stato del run
            // cambia, e il timer di livello va comunque azzerato.
            $run->update([
                'status'                        => 'completed',
                'ended_at'                      => now(),
                'level_started_by_candidate_at' => null,
            ]);

            $totalDurationSeconds = $run->started_at ? Carbon::parse($run->started_at)->diffInSeconds(now()) : null;

            $this->logEvent($session->id, 'EXAM_COMPLETED', 'system', $run->id_candidate, [
                'started_at'              => $run->started_at ? Carbon::parse($run->started_at)->toIso8601String() : null,
                'ended_at'                => now()->toIso8601String(),
                'total_duration_seconds'  => $totalDurationSeconds,
            ], $run->id_candidate);

            $this->maybeGenerateExamFinishedSnapshot($run, $session);

            $this->broadcastRunsUpdate($session);

            return $result;
        }

        $run->update([
            'current_exam_area_id'          => $next['area']->id,
            'current_exam_level_id'         => $next['level']->id,
            'current_step_started_at'       => now(),
            'current_step'                  => ($run->current_step ?? 0) + 1,
            'level_started_by_candidate_at' => null,
        ]);

        $this->logEvent(
            $session->id,
            'LEVEL_STARTED',
            'system',
            $run->id_candidate,
            $this->buildLevelStartedPayload($next['area'], $next['level']),
            $run->id_candidate
        );

        return $result;
    }

    // =========================================================
    // BROADCASTING
    // =========================================================

    /**
     * Broadcast sicuro con log su exam_session_logs.
     * Un fallimento del broadcast non blocca il flusso dell'esame
     * perché la logica di business è già stata completata.
     */
    private function safeBroadcast(
        int $sessionId,
        object $event,
        string $eventName,
        string $channel
    ): void {
        try {
            broadcast($event);

            $this->logEvent($sessionId, 'BROADCAST_SENT', 'websocket', null, [
                'event'   => $eventName,
                'channel' => $channel,
            ], null);
        } catch (\Throwable $e) {
            $this->logEvent($sessionId, 'BROADCAST_FAILED', 'websocket', null, [
                'event'   => $eventName,
                'channel' => $channel,
                'error'   => $e->getMessage(),
            ], null);

            Log::error('safeBroadcast: broadcast WebSocket fallito', [
                'session_id' => $sessionId,
                'event'   => $eventName,
                'channel' => $channel,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Invia lo stato aggiornato di tutti i run della sessione
     * sia all'esaminatore che ai candidati.
     */
    private function broadcastRunsUpdate(ExamSession $session): void
    {
        $runs = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->with('candidate:id,public_id,name,surname')
            ->get()
            ->map(fn ($run) => [
                'candidate_public_id' => $run->candidate->public_id,
                'candidate_id'        => $run->id_candidate,
                'candidate_name'      => $run->candidate->name . ' ' . $run->candidate->surname,
                'status'              => $run->status,
            ])
            ->all();

        $this->safeBroadcast(
            $session->id,
            new \App\Events\ExamRunsUpdated($session->public_id, $runs),
            'runs.updated',
            "exam-session.{$session->public_id}"
        );
    }

    /**
     * Spinge in tempo reale il singolo log appena creato all'esaminatore
     * (e a chi altro ascolta il canale della sessione). Non passa da
     * safeBroadcast per non raddoppiare il volume di exam_session_logs:
     * qui un eventuale fallimento viene solo loggato su Log::error, senza
     * generare un'altra riga di audit.
     */
    private function broadcastLogCreated(ExamSessionLog $log): void
    {
        // Eventi infrastrutturali sul meccanismo di broadcast stesso: restano
        // salvati per audit ma non vengono spinti in tempo reale (eviterebbero
        // comunque poco valore per l'esaminatore e rischierebbero cicli).
        if (in_array($log->event_type, ['BROADCAST_SENT', 'BROADCAST_FAILED'], true)) {
            return;
        }

        try {
            $session = ExamSession::find($log->id_exam_session);
            if (!$session) {
                Log::warning('broadcastLogCreated: sessione non trovata per il log da trasmettere', [
                    'log_id'          => $log->id,
                    'id_exam_session' => $log->id_exam_session,
                ]);
                return;
            }

            $candidatePublicId = $log->id_candidate
                ? Candidate::where('id', $log->id_candidate)->value('public_id')
                : null;

            broadcast(new \App\Events\ExamSessionLogCreated($session->public_id, [
                'id'                  => $log->id,
                'event_type'          => $log->event_type,
                'actor_type'          => $log->actor_type,
                'actor_id'            => $log->actor_id,
                'candidate_public_id' => $candidatePublicId,
                'payload'             => $log->payload,
                'created_at'          => $log->created_at?->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            Log::error('broadcastLogCreated: broadcast del log in tempo reale fallito', [
                'log_id' => $log->id,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    // =========================================================
    // SESSION LOGGING
    // =========================================================
    private function logEvent(
        int $sessionId,
        string $eventType,
        string $actorType,
        ?int $actorId = null,
        ?array $payload = null,
        ?int $candidateId = null
    ): void {
        try {
            $log = ExamSessionLog::create([
                'id_exam_session' => $sessionId,
                'event_type'      => $eventType,
                'actor_type'      => $actorType,
                'actor_id'        => $actorId,
                'id_candidate'    => $candidateId,
                'payload'         => $payload,
            ]);

            $log->refresh();

            $this->broadcastLogCreated($log);
        } catch (\Throwable $e) {
            Log::error('logEvent: scrittura del log di sessione fallita', [
                'message'    => $e->getMessage(),
                'session_id' => $sessionId,
                'event_type' => $eventType,
            ]);
        }
    }

    // =========================================================
    // LOG EVENTO CLIENT (es. stato connessione WebSocket)
    // =========================================================

    public function logClientEvent(
        string $sessionPublicId,
        string $eventType,
        ?int $candidateId,
        array $payload = []
    ): void {
        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

        $this->logEvent(
            $session->id,
            $eventType,
            'websocket',
            $candidateId,
            $payload,
            $candidateId
        );
    }

    private function withMeta(array $payload, ?array $requestMeta): array
    {
        if ($requestMeta) {
            $payload['request_meta'] = $requestMeta;
        }
        return $payload;
    }

    private function buildCompletedPayload(ExamSession $session, ExamSessionCandidateRun $run): array
    {
        return [
            'session'       => $session,
            'run'           => $run,
            'exam_completed'=> true,
            'current_area'  => null,
            'current_level' => null,
            'questions'     => collect(),
        ];
    }

    // =========================================================
// EXAM PROGRESS (per lo stepper/panoramica frontend)
// =========================================================
    public function getExamProgress(string $sessionPublicId, int $candidateId): array
    {
        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

        $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)
            ->firstOrFail();

        $areas = $this->getOrderedAreasWithRules($session->id_exam);

        $isCompleted = in_array($run->status, ['completed', 'timeout', 'terminated']);

        $currentAreaId  = $run->current_exam_area_id;
        $currentLevelId = $run->current_exam_level_id;

        $currentAreaOrder = $currentAreaId
            ? $areas->firstWhere('id', $currentAreaId)?->order
            : null;

        $currentLevel = $currentLevelId ? ExamLevel::find($currentLevelId) : null;

        $progress = $areas->map(function ($area) use (
            $session, $candidateId, $currentAreaId, $currentLevelId,
            $currentAreaOrder, $currentLevel, $isCompleted
        ) {
            $levels = $this->getOrderedLevelsForArea($area->id);
            $highestPassedOrder = null;

            $levelsProgress = $levels->map(function ($level) use (
                $session, $candidateId, $area, $currentAreaId, $currentLevelId,
                $currentAreaOrder, $currentLevel, $isCompleted, &$highestPassedOrder
            ) {
                $result    = $this->computeGroupResult($session->id, $candidateId, $area->id, $level->id);
                $attempted = $result['total'] > 0;

                $isCurrent = !$isCompleted
                    && $area->id === $currentAreaId
                    && $level->id === $currentLevelId;

                if ($isCurrent) {
                    $status = 'current';
                } elseif ($attempted) {
                    $status = $result['passed'] ? 'passed' : 'failed_or_skipped';
                    if ($result['passed']) {
                        $highestPassedOrder = $level->order;
                    }
                } elseif ($isCompleted) {
                    // esame concluso, questo livello non è mai stato raggiunto
                    $status = 'failed_or_skipped';
                } elseif ($currentAreaOrder !== null && $area->order < $currentAreaOrder) {
                    // area già lasciata alle spalle, livello mai affrontato al suo interno
                    $status = 'failed_or_skipped';
                } elseif ($area->id === $currentAreaId && $currentLevel) {
                    // stessa area del punto corrente: prima o dopo il livello attuale?
                    $status = $level->order < $currentLevel->order ? 'failed_or_skipped' : 'locked';
                } else {
                    $status = 'locked';
                }

                return [
                    'exam_level_id' => $level->id,
                    'name'          => $level->name,
                    'label'         => $level->label,
                    'order'         => $level->order,
                    'status'        => $status,
                    'correct'       => $attempted ? $result['correct'] : null,
                    'total'         => $attempted ? $result['total'] : null,
                    'passing_score' => $result['passing_score'],
                ];
            })->values();

            // DOPO
            $areaStatus = $levelsProgress->contains(fn ($l) => $l['status'] === 'current')
                ? 'current'
                : ($levelsProgress->every(fn ($l) => $l['status'] === 'locked') ? 'locked' : 'done');

            $highestPassedLevel = $highestPassedOrder !== null
                ? $levels->firstWhere('order', $highestPassedOrder)
                : null;

            // Stessa regola di attestazione di calculateScore: un'area lasciata
            // (status 'done') senza livelli superati attesta comunque il primo
            // livello, se è stato effettivamente affrontato.
            if ($highestPassedLevel === null && $areaStatus === 'done') {
                $firstLevelAttempted = $levelsProgress->first() && $levelsProgress->first()['total'] !== null;
                if ($firstLevelAttempted) {
                    $highestPassedLevel = $levels->first();
                }
            }

            return [
                'exam_area_id'  => $area->id,
                'name'          => $area->name,
                'label'         => $area->label,
                'order'         => $area->order,
                'status'        => $areaStatus,
                'highest_level_passed' => $highestPassedLevel ? [
                    'exam_level_id' => $highestPassedLevel->id,
                    'name'          => $highestPassedLevel->name,
                    'label'         => $highestPassedLevel->label,
                ] : null,
                'levels' => $levelsProgress,
            ];
        })->values();

        return [
            'run_status'        => $run->status,
            'exam_completed'    => $isCompleted,
            'current_area_id'   => $currentAreaId,
            'current_level_id'  => $currentLevelId,
            'areas'             => $progress,
        ];
    }

    // =========================================================
    // CONFERMA AVVIO LIVELLO (timer parte al click, non alla transizione)
    // =========================================================
    public function confirmLevelStart(string $sessionPublicId, int $candidateId): array
    {
        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

        $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)
            ->firstOrFail();

        if ($run->status !== 'in_progress' || !$run->current_exam_area_id || !$run->current_exam_level_id) {
            Log::warning('confirmLevelStart: tentativo di avviare un livello senza run valido in corso', [
                'session_public_id' => $sessionPublicId,
                'candidate_id'      => $candidateId,
                'run_status'        => $run->status,
            ]);
            throw new \Exception('Nessun livello corrente da avviare');
        }

        // Idempotente: se il candidato ha già iniziato questo livello (reload
        // a metà, doppio click) non si resetta il cronometro una seconda volta.
        if (!$run->level_started_by_candidate_at) {
            $run->update(['level_started_by_candidate_at' => now()]);

            $this->logEvent($session->id, 'LEVEL_TIMER_STARTED', 'candidate', $candidateId, [
                'exam_area_id'  => $run->current_exam_area_id,
                'exam_level_id' => $run->current_exam_level_id,
                'started_at'    => now()->toIso8601String(),
            ], $candidateId);
        }

        $rule = ExamExtractionRule::where('exam_area_id', $run->current_exam_area_id)
            ->where('exam_level_id', $run->current_exam_level_id)
            ->first();

        if (!$rule) {
            Log::error('confirmLevelStart: regola di estrazione mancante per il livello corrente', [
                'session_public_id' => $sessionPublicId,
                'candidate_id'      => $candidateId,
                'exam_area_id'      => $run->current_exam_area_id,
                'exam_level_id'     => $run->current_exam_level_id,
            ]);
        }

        return [
            'level_ends_at' => $rule
                ? Carbon::parse($run->level_started_by_candidate_at)->addMinutes($rule->duration_minutes)
                : null,
        ];
    }

    // =========================================================
// SUBMIT LEVEL (batch) — invio esplicito di tutto il livello,
// con finalizzazione forzata indipendente dal conteggio risposte
// =========================================================
    public function submitLevelAnswers(
        string $sessionPublicId,
        int $candidateId,
        array $answers,
        ?array $requestMeta = null
    ): array {
        return DB::transaction(function () use ($sessionPublicId, $candidateId, $answers, $requestMeta) {
            $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

            if ($session->status !== 'live') {
                Log::warning('submitLevelAnswers: tentativo di invio su sessione chiusa', [
                    'session_public_id' => $sessionPublicId,
                    'candidate_id'      => $candidateId,
                ]);
                throw new \Exception('La sessione è chiusa');
            }

            // Lock a livello di riga: se due richieste submit-level arrivano
            // quasi simultaneamente per lo stesso run (doppio click, race tra
            // submit manuale e auto-submit del countdown), la seconda aspetta
            // che la prima committi prima di leggere lo stato — non lavorano
            // più su uno snapshot stantio in parallelo.
            $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
                ->where('id_candidate', $candidateId)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotenza: se quando la seconda richiesta acquisisce il lock
            // il run è già stato finalizzato dalla prima, non è un errore —
            // è la stessa intenzione del candidato arrivata due volte. Si
            // risponde con successo invece di lanciare un'eccezione che il
            // frontend non può distinguere da un vero fallimento.
            if (in_array($run->status, ['completed', 'timeout', 'terminated'])) {
                Log::warning('submitLevelAnswers: invio ricevuto dopo finalizzazione già avvenuta (idempotenza)', [
                    'session_public_id' => $sessionPublicId,
                    'candidate_id'      => $candidateId,
                    'run_status'        => $run->status,
                ]);
                return ['already_finalized' => true, 'correct' => null, 'total' => null, 'passed' => null];
            }

            if ($run->status !== 'in_progress') {
                Log::warning('submitLevelAnswers: tentativo di invio con run non in_progress', [
                    'session_public_id' => $sessionPublicId,
                    'candidate_id'      => $candidateId,
                    'run_status'        => $run->status,
                ]);
                throw new \Exception('Esame non attivo per il candidato');
            }

            $this->ensureGlobalTimeNotExpired($session, $run);
            $run = $this->ensureCurrentGroupIsValid($run, $session);

            if ($run->status === 'completed') {
                Log::warning('submitLevelAnswers: run finalizzato durante la validazione del gruppo corrente', [
                    'session_public_id' => $sessionPublicId,
                    'candidate_id'      => $candidateId,
                ]);
                return ['already_finalized' => true, 'correct' => null, 'total' => null, 'passed' => null];
            }

            $areaId  = $run->current_exam_area_id;
            $levelId = $run->current_exam_level_id;

            foreach ($answers as $item) {
                $question = Question::where('public_id', $item['question_id'])->first();

                if (!$question || (int) $question->exam_area_id !== (int) $areaId || (int) $question->exam_level_id !== (int) $levelId) {
                    Log::warning('submitLevelAnswers: accesso non autorizzato a domanda fuori dal livello corrente', [
                        'session_public_id' => $sessionPublicId,
                        'candidate_id'      => $candidateId,
                        'question_id'       => $item['question_id'] ?? null,
                    ]);
                    $this->logEvent($session->id, 'UNAUTHORIZED_QUESTION_ACCESS', 'candidate', $candidateId,
                        ['question_id' => $item['question_id']], $candidateId);
                    continue;
                }

                $assignedQuestion = ExamSessionCandidateQuestion::where('id_question', $question->id)
                    ->where('id_candidate_run', $run->id)->exists();
                if (!$assignedQuestion) {
                    Log::warning('submitLevelAnswers: domanda non assegnata al run, ignorata', [
                        'session_public_id' => $sessionPublicId,
                        'candidate_id'      => $candidateId,
                        'question_id'       => $question->id,
                    ]);
                    continue;
                }

                $alreadyAnswered = ExamSessionAnswer::where('id_exam_session', $session->id)
                    ->where('id_candidate', $candidateId)
                    ->where('id_question', $question->id)->exists();
                if ($alreadyAnswered) {
                    Log::warning('submitLevelAnswers: risposta duplicata ignorata', [
                        'session_public_id' => $sessionPublicId,
                        'candidate_id'      => $candidateId,
                        'question_id'       => $question->id,
                    ]);
                    continue;
                }

                $selectedAnswer = Answer::where('public_id', $item['answer_id'])
                    ->where('id_question', $question->id)->first();
                if (!$selectedAnswer) {
                    Log::warning('submitLevelAnswers: risposta selezionata non valida per la domanda', [
                        'session_public_id' => $sessionPublicId,
                        'candidate_id'      => $candidateId,
                        'question_id'       => $question->id,
                        'answer_public_id'  => $item['answer_id'] ?? null,
                    ]);
                    continue;
                }

                $correctAnswer = Answer::where('id_question', $question->id)->where('is_correct', 'true')->first();

                if (!$correctAnswer) {
                    Log::error('submitLevelAnswers: nessuna risposta corretta configurata per la domanda', [
                        'question_id' => $question->id,
                    ]);
                }

                $isCorrect = $correctAnswer && (int) $correctAnswer->id === (int) $selectedAnswer->id;

                try {
                    ExamSessionAnswer::create([
                        'id_exam_session'      => $session->id,
                        'id_question'          => $question->id,
                        'id_candidate'         => $candidateId,
                        'answer'               => ['answer_id' => $selectedAnswer->id],
                        'is_correct'           => $isCorrect,
                        'time_spent_seconds'   => $item['time_spent_seconds'] ?? null,
                        'id_exam_session_step' => $run->current_step,
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    if ($e->getCode() === '23505') {
                        Log::warning('submitLevelAnswers: race condition su risposta duplicata intercettata a livello DB', [
                            'session_id'   => $session->id,
                            'candidate_id' => $candidateId,
                            'question_id'  => $question->id,
                        ]);
                        continue;
                    }

                    Log::error('submitLevelAnswers: errore query inatteso durante il salvataggio della risposta', [
                        'session_id'   => $session->id,
                        'candidate_id' => $candidateId,
                        'question_id'  => $question->id,
                        'error'        => $e->getMessage(),
                    ]);
                    throw $e;
                }

                $this->logEvent($session->id, 'ANSWER_SUBMITTED', 'candidate', $candidateId, $this->withMeta([
                    'question_id'        => $question->id,
                    'is_correct'         => $isCorrect,
                    'time_spent_seconds' => $item['time_spent_seconds'] ?? null,
                ], $requestMeta), $candidateId);
            }

            return $this->finalizeCurrentGroupAndAdvance($run, $session, 'submitted_by_candidate');
        });
    }
}
