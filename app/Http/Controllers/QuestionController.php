<?php

namespace App\Http\Controllers;

use App\Models\Answer;
use App\Models\Exam;
use App\Models\ExamArea;
use App\Models\ExamLevel;
use App\Models\Question;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;


class QuestionController extends Controller
{
    use AuthorizesRequests;

    // ──────────────────────────────────────────────────────────────────────────
    // STRUTTURA: aree + livelli di un esame
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Ritorna aree e livelli associati a un esame (tramite public_id).
     *
     * GET /exams/{publicId}/structure
     *
     * Response example:
     * [
     *   {
     *     "public_id": "abc123",
     *     "name": "matematica",
     *     "label": "Matematica",
     *     "order": 1,
     *     "levels": [
     *       { "public_id": "xyz789", "name": "base", "label": "Base", "order": 1 },
     *       ...
     *     ]
     *   },
     *   ...
     * ]
     */
    public function getStructure(string $publicId)
    {
        $exam = Exam::where('public_id', $publicId)->firstOrFail();

        $areas = ExamArea::with('levels')
            ->where('exam_id', $exam->id)
            ->orderBy('order')
            ->get();

        return response()->json($areas);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CRUD domande
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Lista domande per esame (con area e livello).
     */
    public function index(string $publicId)
    {
        $this->authorize('viewAny', Question::class);

        $exam = Exam::where('public_id', $publicId)->firstOrFail();

        $questions = Question::with(['answers', 'area', 'level'])
            ->where('exam_id', $exam->id)
            ->get();

        return response()->json($questions);
    }

    /**
     * Creazione domanda + risposte.
     *
     * Riceve area_public_id e level_public_id (non gli id numerici).
     */
    public function store(Request $request, string $publicId)
    {
        $this->authorize('create', Question::class);

        $validated = $request->validate([
            'text'            => 'required|string',
            'type'            => 'required|string',
            'points'          => 'nullable|numeric',
            'area_public_id'  => 'nullable|string',
            'level_public_id' => 'nullable|string',
            'answers'         => 'required|array|min:2',
            'answers.*.text'       => 'required|string',
            'answers.*.is_correct' => 'required|string',
        ]);

        if (!collect($validated['answers'])->contains('is_correct', true)) {
            return response()->json(['error' => 'Almeno una risposta deve essere corretta'], 422);
        }

        $exam = Exam::where('public_id', $publicId)->firstOrFail();

        // Risolvi area e livello dagli public_id ricevuti
        [$areaId, $levelId] = $this->resolveAreaAndLevel(
            $exam->id,
            $validated['area_public_id']  ?? null,
            $validated['level_public_id'] ?? null,
            resolveByPublicId: true
        );

        DB::transaction(function () use ($validated, $exam, $areaId, $levelId) {

            $question = Question::create([
                'exam_id'       => $exam->id,
                'exam_area_id'  => $areaId,
                'exam_level_id' => $levelId,
                'text'          => $validated['text'],
                'type'          => $validated['type'],
                'points'        => $validated['points'] ?? null,
            ]);

            foreach ($validated['answers'] as $answer) {
                Answer::create([
                    'id_question' => $question->id,
                    'text'        => $answer['text'],
                    'is_correct'  => $answer['is_correct'],
                ]);
            }
        });

        return response()->json(['message' => 'Domanda creata'], 201);
    }

    /**
     * Update domanda + risposte (full replace).
     */
    public function update(Request $request, Question $question)
    {
        $this->authorize('update', $question);

        $validated = $request->validate([
            'text'            => 'required|string',
            'type'            => 'required|string',
            'points'          => 'nullable|numeric',
            'area_public_id'  => 'nullable|string',
            'level_public_id' => 'nullable|string',
            'answers'         => 'required|array|min:2',
            'answers.*.text'       => 'required|string',
            'answers.*.is_correct' => 'required|string',
        ]);

        if (!collect($validated['answers'])->contains('is_correct', true)) {
            return response()->json(['error' => 'Almeno una risposta deve essere corretta'], 422);
        }

        [$areaId, $levelId] = $this->resolveAreaAndLevel(
            $question->exam_id,
            $validated['area_public_id']  ?? null,
            $validated['level_public_id'] ?? null,
            resolveByPublicId: true
        );

        DB::transaction(function () use ($validated, $question, $areaId, $levelId) {

            $question->update([
                'exam_area_id'  => $areaId,
                'exam_level_id' => $levelId,
                'text'          => $validated['text'],
                'type'          => $validated['type'],
                'points'        => $validated['points'] ?? null,
            ]);

            $question->answers()->delete();

            foreach ($validated['answers'] as $answer) {
                Answer::create([
                    'id_question' => $question->id,
                    'text'        => $answer['text'],
                    'is_correct'  => $answer['is_correct'],
                ]);
            }
        });

        return response()->json(['message' => 'Domanda aggiornata']);
    }

    /**
     * Delete domanda.
     */
    public function destroy(string $publicId)
    {
        $question = Question::where('public_id', $publicId)->firstOrFail();

        $this->authorize('delete', $question);

        $question->delete();

        return response()->json(['message' => 'Domanda eliminata']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // IMPORT Excel
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Import da Excel.
     *
     * Colonne attese (header row):
     *   text | type | area | level | answer_1 | answer_2 | answer_3 | answer_4 | is_correct
     *
     * - "area"  → name di ExamArea  (se non esiste viene creata)
     * - "level" → name di ExamLevel associato a quell'area (se non esiste viene creato)
     * - Deduplica su: testo domanda + prima risposta + seconda risposta
     */
    public function import(Request $request, string $publicId)
    {
        $this->authorize('import', Question::class);

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,xlsm'
        ]);

        $exam = Exam::where('public_id', $publicId)->firstOrFail();

        $spreadsheet = IOFactory::load($request->file('file')->getPathname());
        $rows = $spreadsheet->getActiveSheet()->toArray();

        if (count($rows) < 2) {
            return response()->json(['error' => 'File vuoto'], 422);
        }

        $expectedHeaders = [
            'text', 'type', 'area', 'level',
            'answer_1', 'answer_2', 'answer_3', 'answer_4', 'is_correct'
        ];

        $headers = array_map(fn($h) => strtolower(trim($h ?? '')), $rows[0]);
        $headers = array_values(array_filter($headers, fn($h) => $h !== ''));

        if ($headers !== $expectedHeaders) {
            return response()->json([
                'error'    => 'Formato file non valido',
                'expected' => $expectedHeaders,
                'received' => $headers,
            ], 422);
        }

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        DB::transaction(function () use ($rows, $exam, &$inserted, &$updated, &$skipped) {

            // Cache aree e livelli già esistenti per questo esame
            // per evitare N query dentro il loop
            $areaCache  = ExamArea::where('exam_id', $exam->id)
                ->get()
                ->keyBy(fn($a) => strtolower(trim($a->name)));   // 'name' → model

            $levelCache = collect(); // verrà popolata a richiesta

            $existingQuestions = Question::with('answers')
                ->where('exam_id', $exam->id)
                ->get();

            foreach ($rows as $index => $row) {

                if ($index === 0) continue;

                // Mappa le colonne
                [
                    $text, $type, $areaName, $levelName,
                    $a1, $a2, $a3, $a4, $correctKey
                ] = array_map(fn($v) => is_string($v) ? trim($v) : $v, $row);

                // Salta righe incomplete
                if (!$text || !$a1 || !$a2) continue;

                $answersMap = [
                    'answer_1' => $a1,
                    'answer_2' => $a2,
                    'answer_3' => $a3,
                    'answer_4' => $a4,
                ];

                if (!isset($answersMap[$correctKey])) continue;

                // ── Risolvi / crea ExamArea ───────────────────────────────────
                $areaKey = strtolower(trim((string) $areaName));
                if ($areaKey && !$areaCache->has($areaKey)) {
                    $newArea = ExamArea::create([
                        'exam_id' => $exam->id,
                        'name'    => $areaKey,
                        'label'   => ucfirst($areaKey),
                        'order'   => $areaCache->count() + 1,
                    ]);
                    $areaCache->put($areaKey, $newArea);
                }
                $area   = $areaKey ? $areaCache->get($areaKey) : null;
                $areaId = $area?->id;

                // ── Risolvi / crea ExamLevel ──────────────────────────────────
                $levelKey      = strtolower(trim((string) $levelName));
                $levelCacheKey = ($areaId ?? 'null') . '.' . $levelKey;

                if ($areaId && $levelKey && !$levelCache->has($levelCacheKey)) {
                    // Carica livelli dell'area se non ancora in cache
                    $existingLevels = ExamLevel::where('exam_area_id', $areaId)
                        ->get()
                        ->keyBy(fn($l) => strtolower(trim($l->name)));

                    foreach ($existingLevels as $lName => $lModel) {
                        $levelCache->put($areaId . '.' . $lName, $lModel);
                    }

                    // Se ancora non c'è, crea
                    if (!$levelCache->has($levelCacheKey)) {
                        $newLevel = ExamLevel::create([
                            'exam_area_id' => $areaId,
                            'name'         => $levelKey,
                            'label'        => ucfirst($levelKey),
                            'order'        => $existingLevels->count() + 1,
                        ]);
                        $levelCache->put($levelCacheKey, $newLevel);
                    }
                }
                $levelId = ($areaId && $levelKey)
                    ? $levelCache->get($levelCacheKey)?->id
                    : null;

                // ── Deduplica ─────────────────────────────────────────────────
                $normalizedText = strtolower($text);
                $normalizedA1   = strtolower($a1);
                $normalizedA2   = strtolower($a2);

                $foundExact   = null;
                $foundPartial = null;

                foreach ($existingQuestions as $q) {
                    $answers    = $q->answers->values();
                    $qa1        = strtolower(trim($answers[0]->text ?? ''));
                    $qa2        = strtolower(trim($answers[1]->text ?? ''));
                    $matchCount = 0;

                    if (strtolower(trim($q->text)) === $normalizedText) $matchCount++;
                    if ($qa1 === $normalizedA1) $matchCount++;
                    if ($qa2 === $normalizedA2) $matchCount++;

                    if ($matchCount === 3) { $foundExact   = $q; break; }
                    if ($matchCount >= 2)    $foundPartial = $q;
                }

                if ($foundExact) {
                    $skipped++;
                    continue;
                }

                if ($foundPartial) {
                    $foundPartial->update([
                        'text'          => $text,
                        'type'          => $type,
                        'exam_area_id'  => $areaId,
                        'exam_level_id' => $levelId,
                    ]);
                    $foundPartial->answers()->delete();
                    foreach ($answersMap as $key => $value) {
                        if (!$value) continue;
                        Answer::create([
                            'id_question' => $foundPartial->id,
                            'text'        => $value,
                            'is_correct'  => ($key === $correctKey) ? 'true' : 'false',
                        ]);
                    }
                    $updated++;
                    continue;
                }

                // Nuova domanda
                $question = Question::create([
                    'exam_id'       => $exam->id,
                    'exam_area_id'  => $areaId,
                    'exam_level_id' => $levelId,
                    'text'          => $text,
                    'type'          => $type,
                ]);
                foreach ($answersMap as $key => $value) {
                    if (!$value) continue;
                    Answer::create([
                        'id_question' => $question->id,
                        'text'        => $value,
                        'is_correct'  => ($key === $correctKey) ? 'true' : 'false',
                    ]);
                }
                $inserted++;
            }
        });

        return response()->json([
            'inserted' => $inserted,
            'updated'  => $updated,
            'skipped'  => $skipped,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Template download
    // ──────────────────────────────────────────────────────────────────────────

    public function downloadTemplate()
    {

        $path = 'templates/quiz_template.xlsx';//C:\xampp\htdocs\Progetti\gcerti\exam\gcerti_exam_backend\storage\app\templates\quiz_template.xlsx

        if (!Storage::disk('local')->exists($path)) {
            return response()->json(['error' => 'Template non trovato'], 404);
        }

        return Storage::disk('local')->download($path, 'quiz_template.xlsx');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helper privato
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Risolve exam_area_id e exam_level_id a partire da public_id
     * (usato in store/update dove il frontend manda public_id, non id numerici).
     *
     * @return array{int|null, int|null}
     */
    private function resolveAreaAndLevel(
        int     $examId,
        ?string $areaPublicId,
        ?string $levelPublicId,
        bool    $resolveByPublicId = true
    ): array {
        $areaId  = null;
        $levelId = null;

        if ($areaPublicId) {
            $area = ExamArea::where('public_id', $areaPublicId)
                ->where('exam_id', $examId)
                ->firstOrFail();
            $areaId = $area->id;
        }

        if ($levelPublicId && $areaId) {
            $level = ExamLevel::where('public_id', $levelPublicId)
                ->where('exam_area_id', $areaId)
                ->firstOrFail();
            $levelId = $level->id;
        }

        return [$areaId, $levelId];
    }
}
