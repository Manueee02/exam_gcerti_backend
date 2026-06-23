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

    /**
     * Helper: crea uno scenario completo
     */
    private function setupExamScenario(int $nQuestions = 5, int $nAvailable = 10, int $passingScore = 60): array
    {
        $exam = Exam::factory()->create(['duration_minutes' => 45]);
        $area = ExamArea::factory()->create(['exam_id' => $exam->id]);
        $level = ExamLevel::factory()->create(['exam_area_id' => $area->id]);

        ExamExtractionRule::factory()->create([
            'exam_area_id' => $area->id,
            'exam_level_id' => $level->id,
            'n_questions' => $nQuestions,
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

        // Stesso pattern di livelli ripetuto identico per ogni area
        // (valori presi 1:1 dal dump di exam_extraction_rules)
        $livelliTemplate = [
            ['name' => 'b1', 'label' => 'B1', 'order' => 0, 'n_questions' => 2, 'duration_minutes' => 3, 'passing_score' => 2],
            ['name' => 'b2', 'label' => 'B2', 'order' => 1, 'n_questions' => 2, 'duration_minutes' => 3, 'passing_score' => 2],
            ['name' => 'i3', 'label' => 'I3', 'order' => 2, 'n_questions' => 2, 'duration_minutes' => 4, 'passing_score' => 2],
            ['name' => 'i4', 'label' => 'I4', 'order' => 3, 'n_questions' => 2, 'duration_minutes' => 4, 'passing_score' => 2],
            ['name' => 'a5', 'label' => 'A5', 'order' => 4, 'n_questions' => 4, 'duration_minutes' => 6, 'passing_score' => 3],
            ['name' => 'a6', 'label' => 'A6', 'order' => 5, 'n_questions' => 4, 'duration_minutes' => 6, 'passing_score' => 2],
        ];

        foreach ($areeTemplate as $areaIndex => $areaData) {
            $area = ExamArea::factory()->create([
                'exam_id' => $exam->id,
                'name' => $areaData['name'],
                'label' => $areaData['label'],
                'order' => $areaIndex,
            ]);

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

                // Esattamente n_questions disponibili: nessun margine.
                // Verifica anche che il motore non richieda più del necessario.
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

        return compact('exam', 'plannedExam', 'candidateRecord');
    }

    // ────────────────────────────────────────────────
    // FASE 1/2 — comportamento base
    // ────────────────────────────────────────────────

    public function test_avvia_sessione_e_assegna_domande_secondo_extraction_rules(): void
    {
        $s = $this->setupExamScenario(nQuestions: 5, nAvailable: 10);

        $session = $this->engine->startSession($s['plannedExam']->public_id);

        $this->assertEquals('live', $session->status);
        $this->assertEquals(1, ExamSessionCandidateRun::count());
        $this->assertEquals('pending', ExamSessionCandidateRun::first()->status);

        $run = ExamSessionCandidateRun::first();
        $this->assertEquals(
            5,
            ExamSessionCandidateQuestion::where('id_candidate_run', $run->id)->count()
        );

        $this->assertTrue(ExamSessionLog::where('event_type', 'SESSION_STARTED')->exists());
        $this->assertTrue(ExamSessionLog::where('event_type', 'QUESTIONS_ASSIGNED')->exists());
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

    // ────────────────────────────────────────────────
    // FIX 1 — is_correct non deve mai arrivare al candidato
    // ────────────────────────────────────────────────

    public function test_non_espone_is_correct_al_candidato(): void
    {
        $s = $this->setupExamScenario();
        $session = $this->engine->startSession($s['plannedExam']->public_id);

        $candidateId = $s['candidateRecord']->id_candidate;
        $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)->first();
        $run->update(['status' => 'authorized']);

        $data = $this->engine->getCandidateExam($session->public_id, $candidateId);

        $json = json_encode($data['questions']);

        $this->assertStringNotContainsString('is_correct', $json);
    }

    // ────────────────────────────────────────────────
    // FIX 2 — timeout deve usare exams.duration_minutes
    // ────────────────────────────────────────────────

    public function test_scatta_timeout_secondo_durata_configurata(): void
    {
        $s = $this->setupExamScenario(); // duration_minutes = 45

        $session = $this->engine->startSession($s['plannedExam']->public_id);
        $candidateId = $s['candidateRecord']->id_candidate;
        $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)->first();
        $run->update(['status' => 'authorized']);
        $this->engine->getCandidateExam($session->public_id, $candidateId);

        Carbon::setTestNow(Carbon::now()->addMinutes(50));

        $question = ExamSessionCandidateQuestion::where('id_candidate_run', $run->id)->first();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Tempo esame terminato');

        $this->engine->submitAnswer($session->public_id, $candidateId, $question->id_question, ['answer_id' => 1], 10);
    }

    public function test_non_scatta_timeout_entro_durata_configurata(): void
    {
        $s = $this->setupExamScenario(); // 45 minuti

        $session = $this->engine->startSession($s['plannedExam']->public_id);
        $candidateId = $s['candidateRecord']->id_candidate;
        $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)->first();
        $run->update(['status' => 'authorized']);
        $this->engine->getCandidateExam($session->public_id, $candidateId);

        Carbon::setTestNow(Carbon::now()->addMinutes(30));

        $question = ExamSessionCandidateQuestion::where('id_candidate_run', $run->id)->first();
        $correctAnswer = Answer::where('id_question', $question->id_question)
            ->where('is_correct', 'true')->first();

        $saved = $this->engine->submitAnswer(
            $session->public_id,
            $candidateId,
            $question->id_question,
            ['answer_id' => $correctAnswer->id],
            10
        );

        $this->assertNotNull($saved);
        $this->assertEquals(1, ExamSessionAnswer::count());
    }

    // ────────────────────────────────────────────────
    // FIX 3 — score persistito + passing_score
    // ────────────────────────────────────────────────

    public function test_calcola_e_persiste_score_e_passed(): void
    {
        $s = $this->setupExamScenario(nQuestions: 4, nAvailable: 4, passingScore: 75);

        $session = $this->engine->startSession($s['plannedExam']->public_id);
        $candidateId = $s['candidateRecord']->id_candidate;
        $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)->first();
        $run->update(['status' => 'authorized']);
        $this->engine->getCandidateExam($session->public_id, $candidateId);

        $questions = ExamSessionCandidateQuestion::where('id_candidate_run', $run->id)->get();

        foreach ($questions as $index => $cq) {
            $correct = Answer::where('id_question', $cq->id_question)->where('is_correct', 'true')->first();
            $wrong = Answer::where('id_question', $cq->id_question)->where('is_correct', 'false')->first();
            $chosen = $index < 3 ? $correct : $wrong;

            $this->engine->submitAnswer($session->public_id, $candidateId, $cq->id_question, ['answer_id' => $chosen->id], 5);
        }

        $result = $this->engine->calculateScore($session->public_id, $candidateId);

        $this->assertEquals(75.0, $result['score']);
        $this->assertTrue($result['passed']);

        $run->refresh();
        $this->assertEquals(75.0, (float) $run->score);
        $this->assertTrue((bool) $run->passed);
        $this->assertEquals('completed', $run->status);
    }

    // ────────────────────────────────────────────────
    // Controlli anti-frode esistenti
    // ────────────────────────────────────────────────

    public function test_rifiuta_risposta_a_domanda_non_assegnata(): void
    {
        $s = $this->setupExamScenario();

        $session = $this->engine->startSession($s['plannedExam']->public_id);
        $candidateId = $s['candidateRecord']->id_candidate;
        $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)->first();
        $run->update(['status' => 'authorized']);
        $this->engine->getCandidateExam($session->public_id, $candidateId);

        $assignedIds = ExamSessionCandidateQuestion::where('id_candidate_run', $run->id)
            ->pluck('id_question');

        $foreignQuestion = Question::whereNotIn('id', $assignedIds)->first();

        try {
            $this->engine->submitAnswer($session->public_id, $candidateId, $foreignQuestion->id, ['answer_id' => 1], 5);
            $this->fail('Doveva lanciare eccezione');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Domanda non assegnata', $e->getMessage());
        }

        $this->assertTrue(ExamSessionLog::where('event_type', 'UNAUTHORIZED_QUESTION_ACCESS')->exists());
    }

    // ────────────────────────────────────────────────
    // FIX 4 — vincolo unique anti-duplicati
    // ────────────────────────────────────────────────

    public function test_vincolo_db_impedisce_doppia_risposta(): void
    {
        $s = $this->setupExamScenario();

        $session = $this->engine->startSession($s['plannedExam']->public_id);
        $candidateId = $s['candidateRecord']->id_candidate;
        $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidateId)->first();
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

    public function test_avvia_sessione_con_struttura_digcomp_reale(): void
    {
        $s = $this->setupRealDigCompScenario();

        $session = $this->engine->startSession($s['plannedExam']->public_id);

        $this->assertEquals('live', $session->status);

        $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $s['candidateRecord']->id_candidate)
            ->first();

        // 5 aree x (2+2+2+2+4+4) domande per area = 80 domande totali
        $totalAssigned = ExamSessionCandidateQuestion::where('id_candidate_run', $run->id)->count();

        $this->assertEquals(80, $totalAssigned);

        $this->assertTrue(
            ExamSessionLog::where('id_exam_session', $session->id)
                ->where('event_type', 'QUESTIONS_ASSIGNED')
                ->exists()
        );
    }
}
