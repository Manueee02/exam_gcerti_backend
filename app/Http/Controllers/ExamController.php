<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use Illuminate\Http\Request;

class ExamController extends Controller
{
    /**
     * GET ALL (solo attivi)
     */
    public function index()
    {
        $this->authorize('viewAny', Exam::class);

        $exams = Exam::where('active', 'true')->get();

        return response()->json($exams);
    }

    /**
     * GET SINGLE
     */
    public function show(Exam $exam)
    {
        $this->authorize('view', $exam);

        return response()->json($exam);
    }

    /**
     * CREATE
     */
    public function store(Request $request)
    {
        $this->authorize('create', Exam::class);

        $validated = $request->validate([
            'type' => 'required|string',
            'name' => 'required|string',
            'description' => 'required|string',
            'cost' => 'required|string',
            'color' => 'required|string',
        ]);

        $exam = Exam::create([
            ...$validated,
            'active' => 'true'
        ]);

        return response()->json($exam, 201);
    }

    /**
     * UPDATE
     */
    public function update(Request $request, Exam $exam)
    {
        $this->authorize('update', $exam);

        $validated = $request->validate([
            'type' => 'required|string',
            'name' => 'required|string',
            'description' => 'required|string',
            'cost' => 'required|string',
            'color' => 'required|string',
        ]);

        $exam->update($validated);

        return response()->json($exam);
    }

    /**
     * DELETE (soft via active = false)
     */
    public function destroy(Exam $exam)
    {
        $this->authorize('delete', $exam);

        $exam->update([
            'active' => 'false'
        ]);

        return response()->json([
            'message' => 'Exam disattivato'
        ]);
    }
}
