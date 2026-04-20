<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Models\Answer;
use App\Models\Exam;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QuestionController extends Controller
{
    /**
     * Lista domande per esame
     */
    public function index(string $publicId)
    {
        $this->authorize('viewAny', Question::class);

        $exam = Exam::where('public_key', $publicId)->firstOrFail();

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
            'answers.*.is_correct' => 'required|boolean',
        ]);

        // almeno una risposta corretta
        if (!collect($validated['answers'])->contains('is_correct', true)) {
            return response()->json(['error' => 'Almeno una risposta deve essere corretta'], 422);
        }

        $exam = Exam::where('public_key', $publicId)->firstOrFail();

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
                    'question_id' => $question->id,
                    'text' => $answer['text'],
                    'is_correct' => $answer['is_correct'],
                    'order' => $index + 1,
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
            'answers.*.is_correct' => 'required|boolean',
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
                    'question_id' => $question->id,
                    'text' => $answer['text'],
                    'is_correct' => $answer['is_correct'],
                    'order' => $index + 1,
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
            'file' => 'required|file|mimes:xlsx,csv'
        ]);

        $exam = Exam::where('public_key', $publicId)->firstOrFail();

        $rows = array_map('str_getcsv', file($request->file('file')));

        $inserted = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $exam, &$inserted, &$skipped) {

            foreach ($rows as $index => $row) {

                // skip header
                if ($index === 0) continue;

                [$questionText, $a1, $a2, $a3, $a4, $correctIndex] = $row;

                // normalizzazione testo (anti duplicati)
                $normalized = strtolower(trim($questionText));

                $exists = Question::where('exam_id', $exam->id)
                    ->whereRaw('LOWER(TRIM(text)) = ?', [$normalized])
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                $question = Question::create([
                    'exam_id' => $exam->id,
                    'text' => $questionText,
                    'type' => 'multiple_choice'
                ]);

                $answers = [$a1, $a2, $a3, $a4];

                foreach ($answers as $i => $text) {
                    Answer::create([
                        'question_id' => $question->id,
                        'text' => $text,
                        'is_correct' => ($i + 1) == $correctIndex,
                        'order' => $i + 1
                    ]);
                }

                $inserted++;
            }
        });

        return response()->json([
            'inserted' => $inserted,
            'skipped' => $skipped
        ]);
    }
}
