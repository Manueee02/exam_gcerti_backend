<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamArea;
use App\Models\ExamLevel;
use App\Models\ExamExtractionRule;
use App\Models\Question;
use App\Models\Answer;
use App\Models\PlannedExam;
use App\Models\PlannedExamCandidate;
use App\Models\ExamSessionCandidateRun;
use App\Models\ExamSessionCandidateQuestion;
use App\Models\ExamSessionAnswer;
use App\Models\ExamSessionLog;
use App\Services\ExamEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class ExamEngineServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExamEngineService $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(ExamEngineService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ════════════════════════════════════════════════════════════════
    // HELPER DI SCENARIO
    // ════════════════════════════════════════════════════════════════

    /**
     * Scenario minimo: 1 area, 1 livello. Usato per i test che non
     * riguardano la progressione adattiva (assegnazione, anti-frode,
     * vincoli DB) dove avere più gruppi sarebbe solo rumore.
     */
    private function setupExamScenario(int $nQuestions = 5, int $nAvailable = 10, int $passingScore = 3): array
    {
        $exam = Exam::factory()->create(['duration_minutes' => 45]);
        $area = ExamArea::factory()->create(['exam_id' => $exam->id]);
        $level = ExamLevel::factory()->create(['exam_area_id' => $area->id]);

        ExamExtractionRule::factory()->create([
            'exam_area_id' => $area->id,
            'exam_level_id' => $level->id,
            'n_questions' => $nQuestions,
            // 40 min, non 30: deve restare comodamente sopra ai +30 min
            // usati da test_non_scatta_timeout_globale_entro_durata_configurata,
            // altrimenti il timeout di LIVELLO scatterebbe insieme a quello
            // globale e falserebbe l'esito del test.
            'duration_minutes' => 40,
            'passing_score' => $passingScore,
        ]);

        $questions = Question::factory()->count($nAvailable)->create([
            'exam_id' => $exam->id,
            'exam_area_id' => $area->id,
            'exam_level_id' => $level->id,
        ]);

        $questions->each(function ($q) {
            Answer::factory()->create(['id_question' => $q->id, 'is_correct' => 'true']);
            Answer::factory()->count(3)->create(['id_question' => $q->id, 'is_correct' => 'false']);
        });

        $plannedExam = PlannedExam::factory()->create([
            'id_exam' => $exam->id,
            'date' => Carbon::now()->format('Y-m-d'),
            'time' => Carbon::now()->addMinutes(5)->format('H:i:s'),
        ]);

        $candidateRecord = PlannedExamCandidate::factory()->create([
            'id_planned_exam' => $plannedExam->id,
        ]);

        return compact('exam', 'area', 'level', 'questions', 'plannedExam', 'candidateRecord');
    }

    /**
     * Scenario "reale ma leggero": 2 aree, 1 livello ciascuna, con
     * nomi/etichette realistici. Usato per i casi limite della
     * macchina a stati (timeout di livello, domanda fuori gruppo)
     * dove la struttura completa a 5x6 sarebbe inutilmente lenta.
     */
    private function setupTwoAreaScenario(): array
    {
        $exam = Exam::factory()->create(['duration_minutes' => 30]);

        $area1 = ExamArea::factory()->create([
            'exam_id' => $exam->id, 'name' => 'a1',
            'label' => 'Alfabetizzazione su informazione e dati', 'order' => 0,
        ]);
        $level1 = ExamLevel::factory()->create([
            'exam_area_id' => $area1->id, 'name' => 'b1', 'label' => 'B1', 'order' => 0,
        ]);
        ExamExtractionRule::factory()->create([
            'exam_area_id' => $area1->id, 'exam_level_id' => $level1->id,
            'n_questions' => 2, 'duration_minutes' => 3, 'passing_score' => 2,
        ]);

        $area2 = ExamArea::factory()->create([
            'exam_id' => $exam->id, 'name' => 'a2',
            'label' => 'Comunicazione e collaborazione', 'order' => 1,
        ]);
        $level2 = ExamLevel::factory()->create([
            'exam_area_id' => $area2->id, 'name' => 'b1', 'label' => 'B1', 'order' => 0,
        ]);
        ExamExtractionRule::factory()->create([
            'exam_area_id' => $area2->id, 'exam_level_id' => $level2->id,
            'n_questions' => 2, 'duration_minutes' => 3, 'passing_score' => 2,
        ]);

        foreach ([[$area1, $level1], [$area2, $level2]] as [$area, $level]) {
            $qs = Question::factory()->count(2)->create([
                'exam_id' => $exam->id, 'exam_area_id' => $area->id, 'exam_level_id' => $level->id,
            ]);
            foreach ($qs as $q) {
                Answer::factory()->create(['id_question' => $q->id, 'is_correct' => 'true']);
                Answer::factory()->count(3)->create(['id_question' => $q->id, 'is_correct' => 'false']);
            }
        }

        $plannedExam = PlannedExam::factory()->create([
            'id_exam' => $exam->id,
            'date' => Carbon::now()->format('Y-m-d'),
            'time' => Carbon::now()->addMinutes(5)->format('H:i:s'),
        ]);

        $candidateRecord = PlannedExamCandidate::factory()->create([
            'id_planned_exam' => $plannedExam->id,
        ]);

        return compact('exam', 'area1', 'level1', 'area2', 'level2', 'plannedExam', 'candidateRecord');
    }

    /**
     * Replica la struttura reale dell'esame DigComp 2.2:
     * 5 aree (a1-a5), ciascuna con 6 livelli (b1,b2,i3,i4,a5,a6),
     * con le stesse extraction rules in uso in produzione.
     */
    private function setupRealDigCompScenario(): array
    {
        $exam = Exam::factory()->create([
            'type' => 'digicomp',
            'name' => 'Digcomp 2.2',
            'duration_minutes' => 50,
            'color' => 'violet',
        ]);

        $areeTemplate = [
            ['name' => 'a1', 'label' => 'Alfabetizzazione su informazione e dati'],
            ['name' => 'a2', 'label' => 'Comunicazione e collaborazione'],
            ['name' => 'a3', 'label' => 'Creazione di contenuti digitali'],
            ['name' => 'a4', 'label' => 'Sicurezza'],
            ['name' => 'a5', 'label' => 'Risolvere problemi'],
        ];

        $livelliTemplate = [
            ['name' => 'b1', 'label' => 'B1', 'order' => 0, 'n_questions' => 2, 'duration_minutes' => 3, 'passing_score' => 2],
            ['name' => 'b2', 'label' => 'B2', 'order' => 1, 'n_questions' => 2, 'duration_minutes' => 3, 'passing_score' => 2],
            ['name' => 'i3', 'label' => 'I3', 'order' => 2, 'n_questions' => 2, 'duration_minutes' => 4, 'passing_score' => 2],
            ['name' => 'i4', 'label' => 'I4', 'order' => 3, 'n_questions' => 2, 'duration_minutes' => 4, 'passing_score' => 2],
            ['name' => 'a5', 'label' => 'A5', 'order' => 4, 'n_questions' => 4, 'duration_minutes' => 6, 'passing_score' => 3],
            ['name' => 'a6', 'label' => 'A6', 'order' => 5, 'n_questions' => 4, 'duration_minutes' => 6, 'passing_score' => 2],
        ];

        $areas = [];

        foreach ($areeTemplate as $areaIndex => $areaData) {
            $area = ExamArea::factory()->create([
                'exam_id' => $exam->id,
                'name' => $areaData['name'],
                'label' => $areaData['label'],
                'order' => $areaIndex,
            ]);

            $levels = [];

            foreach ($livelliTemplate as $livelloData) {
                $level = ExamLevel::factory()->create([
                    'exam_area_id' => $area->id,
                    'name' => $livelloData['name'],
                    'label' => $livelloData['label'],
                    'order' => $livelloData['order'],
                ]);

                ExamExtractionRule::factory()->create([
                    'exam_area_id' => $area->id,
                    'exam_level_id' => $level->id,
                    'n_questions' => $livelloData['n_questions'],
                    'duration_minutes' => $livelloData['duration_minutes'],
                    'passing_score' => $livelloData['passing_score'],
                ]);

                $questions = Question::factory()
                    ->count($livelloData['n_questions'])
                    ->create([
                        'exam_id' => $exam->id,
                        'exam_area_id' => $area->id,
                        'exam_level_id' => $level->id,
                    ]);

                foreach ($questions as $question) {
                    Answer::factory()->create([
                        'id_question' => $question->id,
                        'is_correct' => 'true',
                    ]);
                    Answer::factory()->count(3)->create([
                        'id_question' => $question->id,
                        'is_correct' => 'false',
                    ]);
                }

                $levels[$livelloData['name']] = $level;
            }

            $areas[$areaData['name']] = ['area' => $area, 'levels' => $levels];
        }

        $plannedExam = PlannedExam::factory()->create([
            'id_exam' => $exam->id,
            'date' => Carbon::now()->format('Y-m-d'),
            'time' => Carbon::now()->addMinutes(5)->format('H:i:s'),
        ]);

        $candidateRecord = PlannedExamCandidate::factory()->create([
            'id_planned_exam' => $plannedExam->id,
        ]);

        return compact('exam', 'areas', 'plannedExam', 'candidateRecord');
    }

    // ════════════════════════════════════════════════════════════════
    // HELPER DI SUPPORTO AI TEST (risposta automatica + visibilità)
    // ════════════════════════════════════════════════════════════════

    /**
     * Risponde a tutte le domande assegnate per un gruppo area+livello,
     * scegliendo deliberatamente quante risposte corrette dare.
     * Permette di guidare in modo deterministico il percorso del
     * candidato (superare o fallire un livello a piacimento).
     */
    private function answerGroupQuestions(
        string $sessionPublicId,
        int $candidateId,
        int $areaId,
        int $levelId,
        int $numCorrect
    ): void {
        $questions = Question::where('exam_area_id', $areaId)
            ->where('exam_level_id', $levelId)
            ->get();

        foreach ($questions as $index => $question) {
            $wantCorrect = $index < $numCorrect;

            $answer = $wantCorrect
                ? Answer::where('id_question', $question->id)->where('is_correct', 'true')->first()
                : Answer::where('id_question', $question->id)->where('is_correct', 'false')->first();

            $this->engine->submitAnswer(
                $sessionPublicId,
                $candidateId,
                $question->id,
                ['answer_id' => $answer->id],
                5
            );
        }
    }

    /**
     * Stampa (dump) il contenuto reale restituito da getCandidateExam():
     * in che area/livello si trova il candidato e quali domande gli
     * sono state effettivamente tirate fuori.
     */
    private function dumpCandidateExamPayload(array $payload, string $nota = ''): void
    {
        if ($payload['exam_completed']) {
            dump("[{$nota}] ESAME COMPLETATO — nessuna domanda da mostrare.");
            return;
        }

        dump(sprintf(
            '[%s] Area corrente: %s (%s) | Livello corrente: %s (%s) | Domande in questo gruppo: %d',
            $nota,
            $payload['current_area']->label,
            $payload['current_area']->name,
            $payload['current_level']->label,
            $payload['current_level']->name,
            $payload['questions']->count()
        ));

        foreach ($payload['questions'] as $cq) {
            dump('   - Domanda #' . $cq->id_question . ': ' . $cq->question->text);
        }
    }

    /**
     * Stampa (dump) la traccia cronologica completa degli eventi
     * registrati su exam_session_logs per quella sessione: è il modo
     * più semplice per "vedere" cosa ha fatto realmente il motore
     * (a quale livello è entrato, quando, con quale esito).
     */
    private function dumpSessionTrace(int $sessionId, string $titolo = 'TRACCIA SESSIONE'): void
    {
        $logs = ExamSessionLog::where('id_exam_session', $sessionId)
            ->orderBy('id')
            ->get()
            ->map(fn ($log) => [
                'evento' => $log->event_type,
                'attore' => $log->actor_type . ($log->actor_id ? "#{$log->actor_id}" : ''),
                'payload' => $log->payload,
                'quando' => optional($log->created_at)->format('H:i:s'),
            ])
            ->toArray();

        dump("══════ {$titolo} ══════");
        dump($logs);
    }

    private function getRun(int $sessionId, int $candidateId): ExamSessionCandidateRun
    {
        return ExamSessionCandidateRun::where('id_exam_session', $sessionId)
            ->where('id_candidate', $candidateId)
            ->firstOrFail();
    }

    // ════════════════════════════════════════════════════════════════
    // ASSEGNAZIONE E APERTURA SESSIONE (invariati dalla Fase 2)
    // ════════════════════════════════════════════════════════════════

    public function test_avvia_sessione_e_assegna_domande_secondo_extraction_rules(): void
    {
        $s = $this->setupExamScenario(nQuestions: 5, nAvailable: 10);

        $session = $this->engine->startSession($s['plannedExam']->public_id);

        $this->assertEquals('live', $session->status);
        $this->assertEquals(1, ExamSessionCandidateRun::count());

        $run = ExamSessionCandidateRun::first();
        $this->assertEquals('pending', $run->status);
        $this->assertEquals($s['area']->id, $run->current_exam_area_id);
        $this->assertEquals($s['level']->id, $run->current_exam_level_id);
        $this->assertNotNull($run->current_step_started_at);

        $this->assertEquals(
            5,
            ExamSessionCandidateQuestion::where('id_candidate_run', $run->id)->count()
        );

        $this->assertTrue(ExamSessionLog::where('event_type', 'SESSION_STARTED')->exists());
        $this->assertTrue(ExamSessionLog::where('event_type', 'QUESTIONS_ASSIGNED')->exists());
        $this->assertTrue(ExamSessionLog::where('event_type', 'LEVEL_STARTED')->exists());
    }

    public function test_rifiuta_apertura_sessione_troppo_presto(): void
    {
        $s = $this->setupExamScenario();
        $s['plannedExam']->update(['time' => Carbon::now()->addMinutes(30)->format('H:i:s')]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('La sessione può essere aperta solo 10 minuti prima');

        $this->engine->startSession($s['plannedExam']->public_id);
    }

    public function test_rifiuta_seconda_sessione_live(): void
    {
        $s = $this->setupExamScenario();
        $this->engine->startSession($s['plannedExam']->public_id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Esiste già una sessione attiva');

        $this->engine->startSession($s['plannedExam']->public_id);
    }

    public function test_fallisce_se_domande_insufficienti(): void
    {
        $s = $this->setupExamScenario(nQuestions: 20, nAvailable: 5);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Domande insufficienti');

        $this->engine->startSession($s['plannedExam']->public_id);
    }

    // ════════════════════════════════════════════════════════════════
    // SICUREZZA E ANTI-FRODE (invariati dalla Fase 2)
    // ════════════════════════════════════════════════════════════════

    public function test_non_espone_is_correct_al_candidato(): void
    {
        $s = $this->setupExamScenario();
        $session = $this->engine->startSession($s['plannedExam']->public_id);

        $candidateId = $s['candidateRecord']->id_candidate;
        $this->getRun($session->id, $candidateId)->update(['status' => 'authorized']);

        $data = $this->engine->getCandidateExam($session->public_id, $candidateId);

        $this->dumpCandidateExamPayload($data, 'is_correct hiding check');

        $json = json_encode($data['questions']);
        $this->assertStringNotContainsString('is_correct', $json);
    }

    public function test_rifiuta_risposta_a_domanda_non_assegnata(): void
    {
        $s = $this->setupExamScenario();

        $session = $this->engine->startSession($s['plannedExam']->public_id);
        $candidateId = $s['candidateRecord']->id_candidate;
        $run = $this->getRun($session->id, $candidateId);
        $run->update(['status' => 'authorized']);
        $this->engine->getCandidateExam($session->public_id, $candidateId);

        $assignedIds = ExamSessionCandidateQuestion::where('id_candidate_run', $run->id)
            ->pluck('id_question');

        // Stessa area/livello del gruppo corrente, ma NON tra quelle estratte
        $foreignQuestion = Question::whereNotIn('id', $assignedIds)
            ->where('exam_area_id', $run->current_exam_area_id)
            ->where('exam_level_id', $run->current_exam_level_id)
            ->first();

        try {
            $this->engine->submitAnswer($session->public_id, $candidateId, $foreignQuestion->id, ['answer_id' => 1], 5);
            $this->fail('Doveva lanciare eccezione');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Domanda non assegnata', $e->getMessage());
        }

        $this->assertTrue(ExamSessionLog::where('event_type', 'UNAUTHORIZED_QUESTION_ACCESS')->exists());
    }

    public function test_rifiuta_domanda_che_appartiene_a_un_gruppo_diverso_da_quello_corrente(): void
    {
        $s = $this->setupTwoAreaScenario();

        $session = $this->engine->startSession($s['plannedExam']->public_id);
        $candidateId = $s['candidateRecord']->id_candidate;
        $this->getRun($session->id, $candidateId)->update(['status' => 'authorized']);
        $payload = $this->engine->getCandidateExam($session->public_id, $candidateId);

        $this->dumpCandidateExamPayload($payload, 'candidato entra nel primo gruppo (area1/b1)');

        // Domanda dell'AREA 2 mentre il candidato è ancora sull'area 1
        $questionFuoriGruppo = Question::where('exam_area_id', $s['area2']->id)
            ->where('exam_level_id', $s['level2']->id)
            ->first();

        try {
            $this->engine->submitAnswer(
                $session->public_id, $candidateId, $questionFuoriGruppo->id, ['answer_id' => 1], 5
            );
            $this->fail('Doveva lanciare eccezione');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Domanda non appartenente al livello corrente', $e->getMessage());
        }

        $this->assertTrue(
            ExamSessionLog::where('event_type', 'UNAUTHORIZED_QUESTION_ACCESS')
                ->where('payload->reason', 'fuori dal livello corrente')
                ->exists()
        );

        $this->dumpSessionTrace($session->id, 'rifiuto domanda fuori gruppo');
    }

    public function test_vincolo_db_impedisce_doppia_risposta(): void
    {
        $s = $this->setupExamScenario();

        $session = $this->engine->startSession($s['plannedExam']->public_id);
        $candidateId = $s['candidateRecord']->id_candidate;
        $run = $this->getRun($session->id, $candidateId);
        $run->update(['status' => 'authorized']);
        $this->engine->getCandidateExam($session->public_id, $candidateId);

        $question = ExamSessionCandidateQuestion::where('id_candidate_run', $run->id)->first();

        $this->engine->submitAnswer($session->public_id, $candidateId, $question->id_question, ['answer_id' => 1], 5);

        $this->expectException(QueryException::class);

        // bypasso il controllo applicativo per simulare la race condition pura
        ExamSessionAnswer::create([
            'id_exam_session' => $session->id,
            'id_question' => $question->id_question,
            'id_candidate' => $candidateId,
            'answer' => ['answer_id' => 1],
            'is_correct' => false,
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // TIMEOUT GLOBALE DELL'ESAME (invariato, copre tutto l'arco esame)
    // ════════════════════════════════════════════════════════════════

    public function test_scatta_timeout_globale_secondo_durata_configurata(): void
    {
        $s = $this->setupExamScenario(); // exam.duration_minutes = 45

        $session = $this->engine->startSession($s['plannedExam']->public_id);
        $candidateId = $s['candidateRecord']->id_candidate;
        $run = $this->getRun($session->id, $candidateId);
        $run->update(['status' => 'authorized']);
        $this->engine->getCandidateExam($session->public_id, $candidateId);

        Carbon::setTestNow(Carbon::now()->addMinutes(50));

        $question = ExamSessionCandidateQuestion::where('id_candidate_run', $run->id)->first();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Tempo esame terminato');

        $this->engine->submitAnswer($session->public_id, $candidateId, $question->id_question, ['answer_id' => 1], 10);
    }

    public function test_non_scatta_timeout_globale_entro_durata_configurata(): void
    {
        $s = $this->setupExamScenario(); // 45 minuti

        $session = $this->engine->startSession($s['plannedExam']->public_id);
        $candidateId = $s['candidateRecord']->id_candidate;
        $run = $this->getRun($session->id, $candidateId);
        $run->update(['status' => 'authorized']);
        $this->engine->getCandidateExam($session->public_id, $candidateId);

        Carbon::setTestNow(Carbon::now()->addMinutes(30));

        $question = ExamSessionCandidateQuestion::where('id_candidate_run', $run->id)->first();
        $correctAnswer = Answer::where('id_question', $question->id_question)
            ->where('is_correct', 'true')->first();

        $saved = $this->engine->submitAnswer(
            $session->public_id, $candidateId, $question->id_question,
            ['answer_id' => $correctAnswer->id], 10
        );

        $this->assertNotNull($saved);
        $this->assertEquals(1, ExamSessionAnswer::count());
    }

    // ════════════════════════════════════════════════════════════════
    // TIMEOUT DI LIVELLO + AVANZAMENTO AUTOMATICO (nuova logica Fase 3)
    // ════════════════════════════════════════════════════════════════

    public function test_timeout_di_livello_avanza_automaticamente_al_gruppo_successivo(): void
    {
        $s = $this->setupTwoAreaScenario(); // level.duration_minutes = 3, exam.duration_minutes = 30

        $session = $this->engine->startSession($s['plannedExam']->public_id);
        $candidateId = $s['candidateRecord']->id_candidate;
        $this->getRun($session->id, $candidateId)->update(['status' => 'authorized']);

        $payloadIniziale = $this->engine->getCandidateExam($session->public_id, $candidateId);
        $this->dumpCandidateExamPayload($payloadIniziale, 'prima del timeout di livello');

        $this->assertEquals('a1', $payloadIniziale['current_area']->name);

        // Supero i 3 minuti del livello, ma resto ben dentro i 30 dell'esame
        Carbon::setTestNow(Carbon::now()->addMinutes(4));

        $payloadDopo = $this->engine->getCandidateExam($session->public_id, $candidateId);
        $this->dumpCandidateExamPayload($payloadDopo, 'dopo il timeout di livello (0 risposte date)');

        // 0 risposte su 2 richieste -> livello fallito -> salta all'area 2
        $this->assertEquals('a2', $payloadDopo['current_area']->name);
        $this->assertEquals('b1', $payloadDopo['current_level']->name);

        $this->assertTrue(
            ExamSessionLog::where('id_exam_session', $session->id)
                ->where('event_type', 'LEVEL_FINISHED')
                ->where('payload->reason', 'timeout')
                ->exists()
        );

        $this->dumpSessionTrace($session->id, 'timeout di livello');
    }

    // ════════════════════════════════════════════════════════════════
    // GRUPPO CORRENTE: cosa vede davvero il candidato
    // ════════════════════════════════════════════════════════════════

    public function test_get_candidate_exam_restituisce_solo_le_domande_del_gruppo_corrente(): void
    {
        $s = $this->setupTwoAreaScenario();

        $session = $this->engine->startSession($s['plannedExam']->public_id);
        $candidateId = $s['candidateRecord']->id_candidate;
        $run = $this->getRun($session->id, $candidateId);
        $run->update(['status' => 'authorized']);

        $totaleAssegnato = ExamSessionCandidateQuestion::where('id_candidate_run', $run->id)->count();
        $this->assertEquals(4, $totaleAssegnato); // 2 aree x 2 domande

        $payload = $this->engine->getCandidateExam($session->public_id, $candidateId);
        $this->dumpCandidateExamPayload($payload, 'verifica scoping per gruppo');

        $this->assertCount(2, $payload['questions']); // solo il gruppo corrente, non tutte e 4

        foreach ($payload['questions'] as $cq) {
            $this->assertEquals($s['area1']->id, $cq->question->exam_area_id);
            $this->assertEquals($s['level1']->id, $cq->question->exam_level_id);
        }
    }

    public function test_completa_automaticamente_il_run_quando_finisce_lunico_gruppo(): void
    {
        $s = $this->setupExamScenario(nQuestions: 3, nAvailable: 3, passingScore: 2);

        $session = $this->engine->startSession($s['plannedExam']->public_id);
        $candidateId = $s['candidateRecord']->id_candidate;
        $this->getRun($session->id, $candidateId)->update(['status' => 'authorized']);
        $this->engine->getCandidateExam($session->public_id, $candidateId);

        // 2 corrette su 3 -> supera (passing_score = 2)
        $this->answerGroupQuestions(
            $session->public_id, $candidateId, $s['area']->id, $s['level']->id, numCorrect: 2
        );

        $run = $this->getRun($session->id, $candidateId);
        $this->assertEquals('completed', $run->status);
        $this->assertNotNull($run->ended_at);
        $this->assertNull($run->current_exam_area_id);
        $this->assertNull($run->current_exam_level_id);

        $this->assertTrue(
            ExamSessionLog::where('event_type', 'LEVEL_FINISHED')
                ->where('payload->passed', true)
                ->exists()
        );
        $this->assertTrue(ExamSessionLog::where('event_type', 'EXAM_COMPLETED')->exists());

        $report = $this->engine->calculateScore($session->public_id, $candidateId);
        dump('REPORT FINALE:', $report);

        $this->assertCount(1, $report['areas']);
        $this->assertEquals($s['level']->name, $report['areas'][0]['highest_level_passed']['name']);

        $this->dumpSessionTrace($session->id, 'run a singolo gruppo, completato');
    }

    // ════════════════════════════════════════════════════════════════
    // SCALATA ADATTIVA AREA/LIVELLO (struttura DigComp reale)
    // ════════════════════════════════════════════════════════════════

    public function test_scala_livelli_consecutivi_nella_stessa_area_se_supera(): void
    {
        $s = $this->setupRealDigCompScenario();
        $area1 = $s['areas']['a1']['area'];
        $b1 = $s['areas']['a1']['levels']['b1'];
        $b2 = $s['areas']['a1']['levels']['b2'];
        $i3 = $s['areas']['a1']['levels']['i3'];

        $session = $this->engine->startSession($s['plannedExam']->public_id);
        $candidateId = $s['candidateRecord']->id_candidate;
        $this->getRun($session->id, $candidateId)->update(['status' => 'authorized']);

        $payload = $this->engine->getCandidateExam($session->public_id, $candidateId);
        $this->dumpCandidateExamPayload($payload, 'partenza: a1/b1');
        $this->assertEquals('b1', $payload['current_level']->name);

        // Supero b1 (2/2) -> mi aspetto di salire a b2, stessa area
        $this->answerGroupQuestions($session->public_id, $candidateId, $area1->id, $b1->id, numCorrect: 2);

        $run = $this->getRun($session->id, $candidateId);
        $this->assertEquals($area1->id, $run->current_exam_area_id);
        $this->assertEquals($b2->id, $run->current_exam_level_id);

        $payload = $this->engine->getCandidateExam($session->public_id, $candidateId);
        $this->dumpCandidateExamPayload($payload, 'dopo aver superato b1: dovrei essere su a1/b2');

        // Supero anche b2 (2/2) -> mi aspetto di salire a i3, stessa area
        $this->answerGroupQuestions($session->public_id, $candidateId, $area1->id, $b2->id, numCorrect: 2);

        $run->refresh();
        $this->assertEquals($area1->id, $run->current_exam_area_id);
        $this->assertEquals($i3->id, $run->current_exam_level_id);

        $payload = $this->engine->getCandidateExam($session->public_id, $candidateId);
        $this->dumpCandidateExamPayload($payload, 'dopo aver superato b2: dovrei essere su a1/i3');

        $this->dumpSessionTrace($session->id, 'scalata consecutiva nella stessa area');
    }

    public function test_fallisce_primo_livello_e_salta_direttamente_alla_prossima_area(): void
    {
        $s = $this->setupRealDigCompScenario();
        $area1 = $s['areas']['a1']['area'];
        $b1Area1 = $s['areas']['a1']['levels']['b1'];
        $area2 = $s['areas']['a2']['area'];
        $b1Area2 = $s['areas']['a2']['levels']['b1'];

        $session = $this->engine->startSession($s['plannedExam']->public_id);
        $candidateId = $s['candidateRecord']->id_candidate;
        $this->getRun($session->id, $candidateId)->update(['status' => 'authorized']);

        $payload = $this->engine->getCandidateExam($session->public_id, $candidateId);
        $this->dumpCandidateExamPayload($payload, 'partenza: a1/b1');

        // 1 corretta su 2 richieste (passing_score=2) -> FALLISCE b1
        $this->answerGroupQuestions($session->public_id, $candidateId, $area1->id, $b1Area1->id, numCorrect: 1);

        $run = $this->getRun($session->id, $candidateId);

        // Deve aver saltato DIRETTAMENTE all'area 2, livello b1 — non a1/b2
        $this->assertEquals($area2->id, $run->current_exam_area_id);
        $this->assertEquals($b1Area2->id, $run->current_exam_level_id);

        $payload = $this->engine->getCandidateExam($session->public_id, $candidateId);
        $this->dumpCandidateExamPayload($payload, 'dopo aver fallito a1/b1: dovrei essere su a2/b1');

        // Nessun LEVEL_STARTED deve mai riferirsi a b2/i3/i4/a5/a6 dell'area 1
        $altriLivelliArea1 = collect($s['areas']['a1']['levels'])
            ->except('b1')
            ->pluck('id');

        $partiteSuAltriLivelliArea1 = ExamSessionLog::where('id_exam_session', $session->id)
            ->where('event_type', 'LEVEL_STARTED')
            ->get()
            ->filter(fn ($log) => in_array($log->payload['exam_level_id'] ?? null, $altriLivelliArea1->toArray()))
            ->count();

        $this->assertEquals(0, $partiteSuAltriLivelliArea1, 'Non deve mai aver tentato gli altri livelli dell\'area 1');

        $this->dumpSessionTrace($session->id, 'fallimento al primo livello, salto area');
    }

    /**
     * @group slow
     */
    public function test_supera_lultimo_livello_di_unarea_e_passa_comunque_alla_prossima(): void
    {
        $s = $this->setupRealDigCompScenario();
        $area1 = $s['areas']['a1']['area'];
        $area2 = $s['areas']['a2']['area'];
        $livelli = $s['areas']['a1']['levels'];

        $session = $this->engine->startSession($s['plannedExam']->public_id);
        $candidateId = $s['candidateRecord']->id_candidate;
        $this->getRun($session->id, $candidateId)->update(['status' => 'authorized']);
        $this->engine->getCandidateExam($session->public_id, $candidateId);

        // Scalo TUTTI i 6 livelli dell'area 1, superandoli tutti
        foreach (['b1', 'b2', 'i3', 'i4', 'a5', 'a6'] as $nomeLivello) {
            $livello = $livelli[$nomeLivello];
            $rule = ExamExtractionRule::where('exam_area_id', $area1->id)
                ->where('exam_level_id', $livello->id)->first();

            $this->answerGroupQuestions(
                $session->public_id, $candidateId, $area1->id, $livello->id,
                numCorrect: $rule->n_questions // tutte corrette, per semplicità
            );

            $payload = $this->engine->getCandidateExam($session->public_id, $candidateId);
            $this->dumpCandidateExamPayload($payload, "dopo aver superato a1/{$nomeLivello}");
        }

        $run = $this->getRun($session->id, $candidateId);

        // Era l'ultimo livello dell'area 1, superato -> deve essere passato all'area 2
        $this->assertEquals($area2->id, $run->current_exam_area_id);
        $this->assertEquals('b1', ExamLevel::find($run->current_exam_level_id)->name);

        $this->dumpSessionTrace($session->id, 'scalata completa area 1 -> passaggio area 2');
    }

    /**
     * @group slow
     *
     * Percorso misto attraverso tutte le 5 aree: il test "vetrina"
     * che mostra l'intero referto finale (livello massimo per area).
     */
    public function test_genera_report_finale_con_livello_piu_alto_superato_per_area(): void
    {
        $s = $this->setupRealDigCompScenario();
        $aree = $s['areas'];

        $session = $this->engine->startSession($s['plannedExam']->public_id);
        $candidateId = $s['candidateRecord']->id_candidate;
        $this->getRun($session->id, $candidateId)->update(['status' => 'authorized']);
        $this->engine->getCandidateExam($session->public_id, $candidateId);

        $rispondi = function (string $area, string $livello, int $numCorrect) use ($session, $candidateId, $aree) {
            $areaModel = $aree[$area]['area'];
            $livelloModel = $aree[$area]['levels'][$livello];
            $this->answerGroupQuestions($session->public_id, $candidateId, $areaModel->id, $livelloModel->id, $numCorrect);

            $payload = $this->engine->getCandidateExam($session->public_id, $candidateId);
            $this->dumpCandidateExamPayload($payload, "dopo {$area}/{$livello} (corrette: {$numCorrect})");
        };

        // Area 1: supera b1, supera b2, fallisce i3 -> salta ad area 2 (atteso: max raggiunto = b2)
        $rispondi('a1', 'b1', 2);
        $rispondi('a1', 'b2', 2);
        $rispondi('a1', 'i3', 1);

        // Area 2: fallisce subito b1 -> salta ad area 3 (atteso: nessun livello superato)
        $rispondi('a2', 'b1', 0);

        // Area 3: scala fino in cima (atteso: max raggiunto = a6)
        $rispondi('a3', 'b1', 2);
        $rispondi('a3', 'b2', 2);
        $rispondi('a3', 'i3', 2);
        $rispondi('a3', 'i4', 2);
        $rispondi('a3', 'a5', 3);
        $rispondi('a3', 'a6', 4);

        // Area 4: fallisce subito b1 -> salta ad area 5 (atteso: nessun livello superato)
        $rispondi('a4', 'b1', 0);

        // Area 5 (ultima): supera b1, poi fallisce b2 -> esame concluso (atteso: max raggiunto = b1)
        $rispondi('a5', 'b1', 2);
        $rispondi('a5', 'b2', 1);

        $run = $this->getRun($session->id, $candidateId);
        $this->assertEquals('completed', $run->status);

        $report = $this->engine->calculateScore($session->public_id, $candidateId);

        dump('═══════════════════════════════════════');
        dump('   CERTIFICATO FINALE (per area)');
        dump('═══════════════════════════════════════');
        dump($report);

        $highestByArea = collect($report['areas'])->keyBy('area_name');

        $this->assertEquals('b2', $highestByArea['a1']['highest_level_passed']['name']);
        $this->assertNull($highestByArea['a2']['highest_level_passed']);
        $this->assertEquals('a6', $highestByArea['a3']['highest_level_passed']['name']);
        $this->assertNull($highestByArea['a4']['highest_level_passed']);
        $this->assertEquals('b1', $highestByArea['a5']['highest_level_passed']['name']);

        $this->dumpSessionTrace($session->id, 'percorso completo su tutte le aree');
    }

    public function test_avvia_sessione_con_struttura_digcomp_reale(): void
    {
        $s = $this->setupRealDigCompScenario();

        $session = $this->engine->startSession($s['plannedExam']->public_id);

        $this->assertEquals('live', $session->status);

        $run = $this->getRun($session->id, $s['candidateRecord']->id_candidate);

        // 5 aree x (2+2+2+2+4+4) domande per area = 80 domande totali
        $totalAssigned = ExamSessionCandidateQuestion::where('id_candidate_run', $run->id)->count();
        $this->assertEquals(80, $totalAssigned);

        $area1 = $s['areas']['a1']['area'];
        $b1 = $s['areas']['a1']['levels']['b1'];
        $this->assertEquals($area1->id, $run->current_exam_area_id);
        $this->assertEquals($b1->id, $run->current_exam_level_id);

        $this->assertTrue(
            ExamSessionLog::where('id_exam_session', $session->id)
                ->where('event_type', 'QUESTIONS_ASSIGNED')
                ->exists()
        );

        $this->dumpSessionTrace($session->id, 'avvio con struttura DigComp completa');
    }
}
