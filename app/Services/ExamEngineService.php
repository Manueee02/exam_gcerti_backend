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
    public function startSession(string $plannedExamPublicId, ?array $requestMeta = null): ExamSession
    {
        return DB::transaction(function () use ($plannedExamPublicId, $requestMeta) {

            /**
             * Recupero planned exam
             */
            $plannedExam = PlannedExam::where(
                'public_id',
                $plannedExamPublicId
            )->firstOrFail();

            $exam = Exam::find($plannedExam->id_exam);

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
             * Recupero candidati (serve anche per il conteggio nel log)
             */
            $candidates = PlannedExamCandidate::where(
                'id_planned_exam',
                $plannedExam->id
            )->get();

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
                $this->withMeta([
                    'planned_exam_id' => $plannedExam->id,
                    'exam_id' => $exam->id ?? null,
                    'exam_name' => $exam->name ?? null,
                    'exam_duration_minutes' => $exam->duration_minutes ?? null,
                    'scheduled_date' => $datePart,
                    'scheduled_time' => $timePart,
                    'total_candidates' => $candidates->count(),
                ], $requestMeta)
            );

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

                /**
                 * Dettaglio per il log: quante domande per ciascun
                 * gruppo area+livello sono state effettivamente estratte,
                 * piu' l'elenco completo degli id (utile per audit/dispute,
                 * mai esposto al candidato tramite l'API di log filtrata).
                 */
                $groupsBreakdown = $questions
                    ->groupBy(fn ($q) => $q->exam_area_id . '-' . $q->exam_level_id)
                    ->map(fn ($group) => [
                        'exam_area_id' => $group->first()->exam_area_id,
                        'exam_level_id' => $group->first()->exam_level_id,
                        'count' => $group->count(),
                    ])
                    ->values();

                $this->logEvent(
                    $session->id,
                    'QUESTIONS_ASSIGNED',
                    'candidate',
                    $candidate->id_candidate,
                    [
                        'questions_count' => count($questions),
                        'question_ids' => $questions->pluck('id')->values(),
                        'groups' => $groupsBreakdown,
                    ]
                );

                /**
                 * Imposto il primo gruppo (area+livello) da cui parte
                 * la scalata del candidato.
                 */
                $firstGroup = $this->resolveFirstGroup($plannedExam->id_exam);

                if ($firstGroup === null) {
                    throw new \Exception(
                        'Esame non configurato correttamente: nessuna area/livello con regole di estrazione'
                    );
                }

                $run->update([
                    'current_exam_area_id' => $firstGroup['area']->id,
                    'current_exam_level_id' => $firstGroup['level']->id,
                    'current_step_started_at' => now(),
                ]);

                $this->logEvent(
                    $session->id,
                    'LEVEL_STARTED',
                    'system',
                    $candidate->id_candidate,
                    $this->buildLevelStartedPayload($firstGroup['area'], $firstGroup['level'])
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
        int $examinerId,
        ?array $requestMeta = null
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
            $this->withMeta([
                'candidate_id' => $candidateId,
                'authorized_at' => now()->toIso8601String(),
            ], $requestMeta)
        );
    }

    /**
     * =========================================================
     * GET CANDIDATE EXAM
     * =========================================================
     */
    public function getCandidateExam(
        string $sessionPublicId,
        int $candidateId,
        ?array $requestMeta = null
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
         * Candidato deve essere autorizzato, in corso, oppure già concluso
         * (completato o per timeout): questi ultimi due restano stati
         * legittimi da interrogare, ad es. dalla schermata di fine sessione.
         */
        if (!in_array($run->status, [
            'authorized',
            'in_progress',
            'completed',
            'timeout',
        ])) {

            throw new \Exception(
                'Candidato non autorizzato'
            );
        }

        $exam = Exam::find($session->id_exam);

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
                $candidateId,
                $this->withMeta([
                    'started_at' => now()->toIso8601String(),
                    'exam_duration_minutes' => $exam->duration_minutes ?? null,
                    'exam_ends_at' => $exam && $exam->duration_minutes
                        ? now()->copy()->addMinutes($exam->duration_minutes)->toIso8601String()
                        : null,
                ], $requestMeta)
            );
        }

        /**
         * Esame già concluso (completato o per timeout): nessun controllo
         * temporale ha più senso qui — se richiamassimo
         * ensureGlobalTimeNotExpired() su un run già 'completed' regolarmente,
         * a tempo scaduto lo riscriverebbe in 'timeout' indebitamente.
         */
        if (in_array($run->status, ['completed', 'timeout'])) {
            return $this->buildCompletedPayload($session, $run);
        }

        $this->ensureGlobalTimeNotExpired($session, $run);
        $run = $this->ensureCurrentGroupIsValid($run, $session);

        if ($run->status === 'completed') {
            return $this->buildCompletedPayload($session, $run);
        }

        /**
         * Recupero SOLO le domande del gruppo (area+livello) corrente
         */
        $questions = ExamSessionCandidateQuestion::with([
            'question.answers'
        ])
            ->where('id_candidate_run', $run->id)
            ->whereHas('question', function ($q) use ($run) {
                $q->where('exam_area_id', $run->current_exam_area_id)
                    ->where('exam_level_id', $run->current_exam_level_id);
            })
            ->orderBy('position')
            ->get();

        // Non esporre MAI la risposta corretta al candidato, e mescola
        // l'ordine delle risposte — stabile per (run, domanda): non cambia
        // se il candidato fa refresh, ma e' diverso da candidato a candidato
        // (cosi' non c'e' mai una posizione "sempre giusta" da intuire).
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

        return [
            'session' => $session,
            'run' => $run,
            'exam_completed' => false,
            'current_area' => ExamArea::find($run->current_exam_area_id),
            'current_level' => ExamLevel::find($run->current_exam_level_id),
            'level_ends_at' => ($rule && $run->current_step_started_at)
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
        ?int $timeSpentSeconds = null,
        ?array $requestMeta = null
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

        // Controlli con side-effect (update + log) che devono persistere
        // ANCHE se subito dopo lanciamo eccezione: restano fuori da
        // qualunque transazione, così non vengono annullati da un rollback.
        $this->ensureGlobalTimeNotExpired($session, $run);
        $run = $this->ensureCurrentGroupIsValid($run, $session);

        if ($run->status === 'completed') {
            throw new \Exception('Esame concluso, non sono ammesse altre risposte');
        }

        $question = Question::find($questionId);

        if (
            !$question ||
            (int) $question->exam_area_id !== (int) $run->current_exam_area_id ||
            (int) $question->exam_level_id !== (int) $run->current_exam_level_id
        ) {
            $this->logEvent(
                $session->id,
                'UNAUTHORIZED_QUESTION_ACCESS',
                'candidate',
                $candidateId,
                $this->withMeta(['question_id' => $questionId, 'reason' => 'fuori dal livello corrente'], $requestMeta)
            );
            throw new \Exception('Domanda non appartenente al livello corrente');
        }

        $assignedQuestion = ExamSessionCandidateQuestion::where('id_question', $questionId)
            ->where('id_candidate_run', $run->id)
            ->exists();

        if (!$assignedQuestion) {
            $this->logEvent(
                $session->id,
                'UNAUTHORIZED_QUESTION_ACCESS',
                'candidate',
                $candidateId,
                $this->withMeta(['question_id' => $questionId], $requestMeta)
            );
            throw new \Exception('Domanda non assegnata');
        }

        $alreadyAnswered = ExamSessionAnswer::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)
            ->where('id_question', $questionId)
            ->exists();

        if ($alreadyAnswered) {
            $this->logEvent(
                $session->id,
                'DUPLICATE_ANSWER_ATTEMPT',
                'candidate',
                $candidateId,
                $this->withMeta(['question_id' => $questionId], $requestMeta)
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

        // Solo la scrittura finale resta in transazione: serve a coprire
        // la race condition sul vincolo unique (Fix 4), non i controlli sopra.
        $savedAnswer = DB::transaction(function () use (
            $session, $candidateId, $questionId, $answer, $isCorrect, $timeSpentSeconds, $run, $requestMeta
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
                        $session->id,
                        'DUPLICATE_ANSWER_ATTEMPT',
                        'candidate',
                        $candidateId,
                        ['question_id' => $questionId]
                    );
                    throw new \Exception('Domanda già risposta');
                }
                throw $e;
            }

            // Log dettagliato server-side (include is_correct: e' materiale
            // di audit per esaminatori, MAI esposto al candidato tramite
            // l'API di log filtrata — vedi getActivityLog()).
            $this->logEvent(
                $session->id,
                'ANSWER_SUBMITTED',
                'candidate',
                $candidateId,
                $this->withMeta([
                    'question_id' => $questionId,
                    'is_correct' => $isCorrect,
                    'time_spent_seconds' => $timeSpentSeconds,
                    'id_exam_session_step' => $run->current_step,
                ], $requestMeta)
            );

            return $saved;
        });

        /**
         * Se il candidato ha risposto a tutte le domande previste
         * dalla regola del gruppo corrente, chiudo il gruppo e avanzo.
         */
        $levelResult = null;

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
                $levelResult = $this->finalizeCurrentGroupAndAdvance($run, $session, 'completed');
            }
        }

        $savedAnswer->makeHidden('is_correct');

        // Esposizione SOLO dell'esito aggregato di livello (punteggio + pass/fail),
        // mai della correttezza della singola domanda. Presente solo quando questa
        // risposta era l'ultima del gruppo.
        if ($levelResult !== null) {
            $savedAnswer->level_result = [
                'correct' => $levelResult['correct'],
                'total' => $levelResult['total'],
                'passed' => $levelResult['passed'],
            ];
        }

        return $savedAnswer;
    }

    /**
     * =========================================================
     * GET ACTIVITY LOG
     * =========================================================
     *
     * Restituisce la cronologia eventi di un candidato in una sessione.
     * $includeSensitiveDetails controlla se rimuovere il campo
     * 'is_correct' (presente su ANSWER_SUBMITTED): false per il candidato
     * stesso, true per admin/esaminatore. Gli esiti AGGREGATI di livello
     * (correct/total/passed su LEVEL_FINISHED) non vengono mai filtrati,
     * perché sono l'informazione che abbiamo deciso di esporre comunque.
     */
    public function getActivityLog(
        string $sessionPublicId,
        int $candidateId,
        bool $includeSensitiveDetails = false
    ): array {

        $session = ExamSession::where('public_id', $sessionPublicId)->firstOrFail();

        $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)
            ->firstOrFail();

        $events = ExamSessionLog::where('id_exam_session', $session->id)
            ->where(function ($q) use ($candidateId) {
                // Eventi specifici del candidato + eventi a livello di
                // intera sessione (actor_id null, es. SESSION_STARTED/ENDED).
                $q->where('actor_id', $candidateId)
                    ->orWhereNull('actor_id');
            })
            ->orderBy('id')
            ->get()
            ->map(function ($log) use ($includeSensitiveDetails) {
                $payload = $log->payload;

                if (!$includeSensitiveDetails && is_array($payload) && array_key_exists('is_correct', $payload)) {
                    unset($payload['is_correct']);
                }

                return [
                    'event_type' => $log->event_type,
                    'actor_type' => $log->actor_type,
                    'actor_id' => $log->actor_id,
                    'payload' => $payload,
                    'created_at' => $log->created_at,
                ];
            })
            ->values();

        return [
            'session' => [
                'public_id' => $session->public_id,
                'status' => $session->status,
                'started_at' => $session->started_at,
                'ended_at' => $session->ended_at,
            ],
            'run' => [
                'status' => $run->status,
                'started_at' => $run->started_at,
                'ended_at' => $run->ended_at,
                'current_step' => $run->current_step,
                'current_exam_area_id' => $run->current_exam_area_id,
                'current_exam_level_id' => $run->current_exam_level_id,
            ],
            'events' => $events,
        ];
    }

    /**
     * =========================================================
     * CALCULATE SCORE
     * =========================================================
     */
    public function calculateScore(
        string $sessionPublicId,
        int $candidateId,
        ?array $requestMeta = null
    ): array {

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

        $this->logEvent(
            $session->id,
            'REPORT_GENERATED',
            'system',
            $candidateId,
            $this->withMeta(['report' => $report], $requestMeta)
        );

        return ['areas' => $report];
    }

    /**
     * =========================================================
     * END SESSION
     * =========================================================
     */
    public function endSession(
        string $sessionPublicId,
        ?array $requestMeta = null
    ): ExamSession {

        return DB::transaction(function () use (
            $sessionPublicId, $requestMeta
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

            // Statistiche aggregate calcolate DOPO l'update sopra, cosi'
            // riflettono lo stato finale di tutti i run di questa sessione.
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
                    'total_candidates' => $statusCounts->sum(),
                ], $requestMeta)
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
     * AREA/LEVEL STATE MACHINE
     * =========================================================
     * Modulare: funziona per qualunque esame, indipendentemente
     * dal numero di aree o livelli configurati. Si basa solo su
     * exam_areas.order, exam_levels.order e exam_extraction_rules.
     */

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
            $currentIndex = $levelsInArea->search(fn ($l) => (int) $l->id === (int) $currentLevelId);

            if ($currentIndex !== false && isset($levelsInArea[$currentIndex + 1])) {
                return [
                    'area' => ExamArea::find($currentAreaId),
                    'level' => $levelsInArea[$currentIndex + 1],
                ];
            }
            // Era l'ultimo livello dell'area, superato -> si passa comunque all'area successiva
        }

        $areas = $this->getOrderedAreasWithRules($examId);
        $areaIndex = $areas->search(fn ($a) => (int) $a->id === (int) $currentAreaId);

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
        $startedAt = Carbon::parse($run->started_at);
        $endTime = $startedAt->copy()->addMinutes($durationMinutes);

        if (now()->gt($endTime)) {
            $run->update(['status' => 'timeout', 'ended_at' => now()]);
            $this->logEvent($session->id, 'EXAM_TIMEOUT', 'system', $run->id_candidate, [
                'exam_area_id' => $run->current_exam_area_id,
                'exam_level_id' => $run->current_exam_level_id,
                'exam_duration_minutes' => $durationMinutes,
                'started_at' => $startedAt->toIso8601String(),
                'timed_out_at' => now()->toIso8601String(),
                'elapsed_seconds' => $startedAt->diffInSeconds(now()),
            ]);
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

    /**
     * Costruisce il payload di log per LEVEL_STARTED, con tutto il
     * contesto utile (nomi, durata, soglie) e non solo gli id grezzi.
     * Usato sia all'avvio sessione che durante l'avanzamento.
     */
    private function buildLevelStartedPayload(ExamArea $area, ExamLevel $level): array
    {
        $rule = ExamExtractionRule::where('exam_area_id', $area->id)
            ->where('exam_level_id', $level->id)
            ->first();

        return [
            'exam_area_id' => $area->id,
            'exam_level_id' => $level->id,
            'area_name' => $area->name,
            'area_label' => $area->label,
            'level_name' => $level->name,
            'level_label' => $level->label,
            'level_duration_minutes' => $rule->duration_minutes ?? null,
            'n_questions_required' => $rule->n_questions ?? null,
            'passing_score_required' => $rule->passing_score ?? null,
        ];
    }

    private function finalizeCurrentGroupAndAdvance(
        ExamSessionCandidateRun $run,
        ExamSession $session,
        string $reason
    ): array {

        $result = $this->computeGroupResult(
            $session->id,
            $run->id_candidate,
            $run->current_exam_area_id,
            $run->current_exam_level_id
        );

        $finishedArea = ExamArea::find($run->current_exam_area_id);
        $finishedLevel = ExamLevel::find($run->current_exam_level_id);
        $finishedRule = ExamExtractionRule::where('exam_area_id', $run->current_exam_area_id)
            ->where('exam_level_id', $run->current_exam_level_id)
            ->first();

        $durationUsedSeconds = $run->current_step_started_at
            ? Carbon::parse($run->current_step_started_at)->diffInSeconds(now())
            : null;

        $this->logEvent(
            $session->id,
            'LEVEL_FINISHED',
            'system',
            $run->id_candidate,
            [
                'exam_area_id' => $run->current_exam_area_id,
                'exam_level_id' => $run->current_exam_level_id,
                'area_name' => $finishedArea->name ?? null,
                'level_name' => $finishedLevel->name ?? null,
                'reason' => $reason,
                'correct' => $result['correct'],
                'total' => $result['total'],
                'passed' => $result['passed'],
                'passing_score_required' => $finishedRule->passing_score ?? null,
                'level_duration_minutes' => $finishedRule->duration_minutes ?? null,
                'duration_used_seconds' => $durationUsedSeconds,
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

            $totalDurationSeconds = $run->started_at
                ? Carbon::parse($run->started_at)->diffInSeconds(now())
                : null;

            $this->logEvent($session->id, 'EXAM_COMPLETED', 'system', $run->id_candidate, [
                'started_at' => $run->started_at ? Carbon::parse($run->started_at)->toIso8601String() : null,
                'ended_at' => now()->toIso8601String(),
                'total_duration_seconds' => $totalDurationSeconds,
            ]);

            return $result;
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
            $this->buildLevelStartedPayload($next['area'], $next['level'])
        );

        return $result;
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

    /**
     * Aggiunge metadati di richiesta (IP, user agent, ...) a un payload
     * di log, solo se forniti dal controller. Tenuto separato dal resto
     * della logica cosi' i metodi del service restano chiamabili senza
     * contesto HTTP (come fanno tutti i test PHPUnit esistenti).
     */
    private function withMeta(array $payload, ?array $requestMeta): array
    {
        if ($requestMeta) {
            $payload['request_meta'] = $requestMeta;
        }

        return $payload;
    }

    /**
     * Payload restituito quando il run è già concluso, sia per
     * completamento naturale che per timeout globale. Centralizzato
     * per evitare di duplicarlo nei due punti in cui serve.
     */
    private function buildCompletedPayload(ExamSession $session, ExamSessionCandidateRun $run): array
    {
        return [
            'session' => $session,
            'run' => $run,
            'exam_completed' => true,
            'current_area' => null,
            'current_level' => null,
            'questions' => collect(),
        ];
    }
}
