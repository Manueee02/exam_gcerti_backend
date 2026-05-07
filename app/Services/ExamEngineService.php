<?php

namespace App\Services;

use App\Models\Answer;
use App\Models\ExamExtractionRule;
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
            $examDateTime = Carbon::parse(
                $plannedExam->date . ' ' . $plannedExam->time
            );

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
    public function getCandidateExam(
        string $sessionPublicId,
        int $candidateId
    ): array {

        $session = ExamSession::where(
            'public_id',
            $sessionPublicId
        )->firstOrFail();

        /**
         * Sessione deve essere live
         */
        if ($session->status !== 'live') {

            throw new \Exception(
                'La sessione non è attiva'
            );
        }

        /**
         * Recupero run candidato
         */
        $run = ExamSessionCandidateRun::where(
            'id_exam_session',
            $session->id
        )
            ->where('id_candidate', $candidateId)
            ->firstOrFail();

        /**
         * Candidato deve essere autorizzato
         */
        if (!in_array($run->status, [
            'authorized',
            'in_progress'
        ])) {

            throw new \Exception(
                'Candidato non autorizzato'
            );
        }

        /**
         * Avvio esame
         */
        if ($run->status === 'authorized') {

            $run->update([
                'status' => 'in_progress',
                'started_at' => now(),
            ]);

            $this->logEvent(
                $session->id,
                'CANDIDATE_STARTED_EXAM',
                'candidate',
                $candidateId
            );
        }

        /**
         * Recupero domande
         */
        $questions = ExamSessionCandidateQuestion::with([
            'question.answers'
        ])
            ->where('id_candidate_run', $run->id)
            ->orderBy('position')
            ->get();

        return [
            'session' => $session,
            'run' => $run,
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

        return DB::transaction(function () use (
            $sessionPublicId,
            $candidateId,
            $questionId,
            $answer,
            $timeSpentSeconds
        ) {

            /**
             * Recupero sessione
             */
            $session = ExamSession::where(
                'public_id',
                $sessionPublicId
            )->firstOrFail();

            /**
             * Sessione deve essere live
             */
            if ($session->status !== 'live') {

                throw new \Exception(
                    'La sessione è chiusa'
                );
            }

            /**
             * Recupero run candidato
             */
            $run = ExamSessionCandidateRun::where(
                'id_exam_session',
                $session->id
            )
                ->where('id_candidate', $candidateId)
                ->firstOrFail();

            /**
             * Deve essere in esecuzione
             */
            if ($run->status !== 'in_progress') {

                throw new \Exception(
                    'Esame non attivo per il candidato'
                );
            }

            /**
             * Controllo timeout
             */
            $durationMinutes = 60;

            if ($run->started_at) {

                $endTime = Carbon::parse(
                    $run->started_at
                )->addMinutes($durationMinutes);

                if (now()->gt($endTime)) {

                    $run->update([
                        'status' => 'timeout',
                        'ended_at' => now(),
                    ]);

                    $this->logEvent(
                        $session->id,
                        'EXAM_TIMEOUT',
                        'system',
                        $candidateId
                    );

                    throw new \Exception(
                        'Tempo esame terminato'
                    );
                }
            }

            /**
             * Controllo domanda assegnata
             */
            $assignedQuestion = ExamSessionCandidateQuestion::where(
                'id_question',
                $questionId
            )
                ->where('id_candidate_run', $run->id)
                ->exists();

            if (!$assignedQuestion) {

                $this->logEvent(
                    $session->id,
                    'UNAUTHORIZED_QUESTION_ACCESS',
                    'candidate',
                    $candidateId,
                    [
                        'question_id' => $questionId,
                    ]
                );

                throw new \Exception(
                    'Domanda non assegnata'
                );
            }

            /**
             * Evita doppie risposte
             */
            $alreadyAnswered = ExamSessionAnswer::where(
                'id_exam_session',
                $session->id
            )
                ->where('id_candidate', $candidateId)
                ->where('id_question', $questionId)
                ->exists();

            if ($alreadyAnswered) {

                $this->logEvent(
                    $session->id,
                    'DUPLICATE_ANSWER_ATTEMPT',
                    'candidate',
                    $candidateId,
                    [
                        'question_id' => $questionId,
                    ]
                );

                throw new \Exception(
                    'Domanda già risposta'
                );
            }

            /**
             * Recupero risposta corretta
             */
            $correctAnswer = Answer::where(
                'id_question',
                $questionId
            )
                ->where('is_correct', 'true')
                ->first();

            $isCorrect = false;

            if ($correctAnswer) {

                $selectedAnswerId = $answer['answer_id'] ?? null;

                $isCorrect = (
                    $correctAnswer->id == $selectedAnswerId
                );
            }

            /**
             * Salvataggio risposta
             */
            $savedAnswer = ExamSessionAnswer::create([
                'id_exam_session' => $session->id,
                'id_question' => $questionId,
                'id_candidate' => $candidateId,
                'answer' => $answer,
                'is_correct' => $isCorrect,
                'time_spent_seconds' => $timeSpentSeconds,
            ]);

            $this->logEvent(
                $session->id,
                'ANSWER_SUBMITTED',
                'candidate',
                $candidateId,
                [
                    'question_id' => $questionId,
                    'is_correct' => $isCorrect,
                ]
            );

            return $savedAnswer;
        });
    }

    /**
     * =========================================================
     * CALCULATE SCORE
     * =========================================================
     */
    public function calculateScore(
        string $sessionPublicId,
        int $candidateId
    ): array {

        $session = ExamSession::where(
            'public_id',
            $sessionPublicId
        )->firstOrFail();

        $answers = ExamSessionAnswer::where(
            'id_exam_session',
            $session->id
        )
            ->where('id_candidate', $candidateId)
            ->get();

        $totalQuestions = $answers->count();

        $correctAnswers = $answers
            ->where('is_correct', true)
            ->count();

        $score = 0;

        if ($totalQuestions > 0) {

            $score = round(
                ($correctAnswers / $totalQuestions) * 100,
                2
            );
        }

        /**
         * Chiusura run
         */
        $run = ExamSessionCandidateRun::where(
            'id_exam_session',
            $session->id
        )
            ->where('id_candidate', $candidateId)
            ->first();

        if ($run) {

            $run->update([
                'status' => 'completed',
                'ended_at' => now(),
            ]);
        }

        $this->logEvent(
            $session->id,
            'SCORE_CALCULATED',
            'system',
            $candidateId,
            [
                'score' => $score,
                'correct_answers' => $correctAnswers,
                'total_questions' => $totalQuestions,
            ]
        );

        return [
            'total_questions' => $totalQuestions,
            'correct_answers' => $correctAnswers,
            'score' => $score,
        ];
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
}

