<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Models\Answer;
use App\Models\Exam;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;


class QuestionController extends Controller
{
    use AuthorizesRequests;

    /**
     * Lista domande per esame
     */
    public function index(string $publicId)
    {
        $this->authorize('viewAny', Question::class);

        $exam = Exam::where('public_id', $publicId)->firstOrFail();

        $questions = Question::with('answers')
            ->where('exam_id', $exam->id)
            ->get();

        return response()->json($questions);
    }

    /**
     * Creazione domanda + risposte
     */
    public function store(Request $request, string $publicId)
    {
        $this->authorize('create', Question::class);

        $validated = $request->validate([
            'text' => 'required|string',
            'type' => 'required|string',
            'area' => 'nullable|string',
            'level' => 'nullable|string',
            'answers' => 'required|array|min:2',
            'answers.*.text' => 'required|string',
            'answers.*.is_correct' => 'required|string',
        ]);

        // almeno una risposta corretta
        if (!collect($validated['answers'])->contains('is_correct', true)) {
            return response()->json(['error' => 'Almeno una risposta deve essere corretta'], 422);
        }

        $exam = Exam::where('public_id', $publicId)->firstOrFail();

        DB::transaction(function () use ($validated, $exam) {

            $question = Question::create([
                'exam_id' => $exam->id,
                'text' => $validated['text'],
                'type' => $validated['type'],
                'area' => $validated['area'] ?? null,
                'level' => $validated['level'] ?? null,
            ]);

            foreach ($validated['answers'] as $index => $answer) {
                Answer::create([
                    'id_question' => $question->id,
                    'text' => $answer['text'],
                    'is_correct' => $answer['is_correct'],
                ]);
            }
        });

        return response()->json(['message' => 'Domanda creata']);
    }

    /**
     * Update domanda + risposte (full replace)
     */
    public function update(Request $request, Question $question)
    {
        $this->authorize('update', $question);

        $validated = $request->validate([
            'text' => 'required|string',
            'type' => 'required|string',
            'area' => 'nullable|string',
            'level' => 'nullable|string',
            'answers' => 'required|array|min:2',
            'answers.*.text' => 'required|string',
            'answers.*.is_correct' => 'required|string',
        ]);

        if (!collect($validated['answers'])->contains('is_correct', true)) {
            return response()->json(['error' => 'Almeno una risposta deve essere corretta'], 422);
        }

        DB::transaction(function () use ($validated, $question) {

            $question->update([
                'text' => $validated['text'],
                'type' => $validated['type'],
                'area' => $validated['area'] ?? null,
                'level' => $validated['level'] ?? null,
            ]);

            // delete vecchie risposte
            $question->answers()->delete();

            // reinserisci
            foreach ($validated['answers'] as $index => $answer) {
                Answer::create([
                    'id_question' => $question->id,
                    'text' => $answer['text'],
                    'is_correct' => $answer['is_correct'],
                ]);
            }
        });

        return response()->json(['message' => 'Domanda aggiornata']);
    }

    /**
     * Delete domanda
     */
    public function destroy(Question $question)
    {
        $this->authorize('delete', $question);

        $question->delete();

        return response()->json(['message' => 'Domanda eliminata']);
    }

    /**
     * Import da Excel (deduplica su testo domanda)
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

        if ($headers !== $expectedHeaders) {
            return response()->json([
                'error' => 'Formato file non valido',
                'expected' => $expectedHeaders,
                'received' => $headers
            ], 422);
        }

        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $exam, &$inserted, &$updated, &$skipped) {

            $existingQuestions = Question::with('answers')
                ->where('exam_id', $exam->id)
                ->get();

            foreach ($rows as $index => $row) {

                if ($index === 0) continue;

                [
                    $text, $type, $area, $level,
                    $a1, $a2, $a3, $a4, $correctKey
                ] = array_map(fn($v) => is_string($v) ? trim(strtolower($v)) : $v, $row);

                if (!$text || !$a1 || !$a2) continue;

                $answersMap = [
                    'answer_1' => $a1,
                    'answer_2' => $a2,
                    'answer_3' => $a3,
                    'answer_4' => $a4,
                ];

                if (!isset($answersMap[$correctKey])) continue;

                $normalizedText = strtolower($text);
                $normalizedA1   = strtolower($a1);
                $normalizedA2   = strtolower($a2);

                $foundExact   = null;
                $foundPartial = null;

                foreach ($existingQuestions as $q) {
                    $answers = $q->answers->values();
                    $qa1 = strtolower(trim($answers[0]->text ?? ''));
                    $qa2 = strtolower(trim($answers[1]->text ?? ''));

                    $matchCount = 0;
                    if (strtolower(trim($q->text)) === $normalizedText) $matchCount++;
                    if ($qa1 === $normalizedA1) $matchCount++;
                    if ($qa2 === $normalizedA2) $matchCount++;

                    if ($matchCount === 3) { $foundExact = $q; break; }
                    if ($matchCount >= 2)    $foundPartial = $q;
                }

                if ($foundExact) { $skipped++; continue; }

                if ($foundPartial) {
                    $foundPartial->update(['text' => $text, 'type' => $type, 'area' => $area, 'level' => $level]);
                    $foundPartial->answers()->delete();
                    foreach ($answersMap as $key => $value) {
                        Answer::create([
                            'id_question' => $foundPartial->id, // ← era $question->id
                            'text' => $value,
                            'is_correct' => ($key === $correctKey) ? 'true' : 'false'                        ]);
                    }
                    $updated++;
                    continue;
                }

                $question = Question::create(['exam_id' => $exam->id, 'text' => $text, 'type' => $type, 'area' => $area, 'level' => $level]);
                foreach ($answersMap as $key => $value) {
                    Answer::create(['id_question' => $question->id, 'text' => $value, 'is_correct' => ($key === $correctKey) ? 'true' : 'false'   ]);
                }
                $inserted++;
            }
        });

        return response()->json(['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped]);
    }

    public function downloadTemplate()
    {

        $path = 'templates/quiz_template.xlsx';//C:\xampp\htdocs\Progetti\gcerti\exam\gcerti_exam_backend\storage\app\templates\quiz_template.xlsx

        if (!Storage::disk('local')->exists($path)) {
            return response()->json(['error' => 'Template non trovato'], 404);
        }

        return Storage::disk('local')->download($path, 'quiz_template.xlsx');
    }
}
