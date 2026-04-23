<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Auth\Access\AuthorizationException;
use Exception;

class ExamController extends Controller
{
    use AuthorizesRequests;

    /**
     * GET ALL (solo attivi)
     */
    public function index()
    {
        try {
            $this->authorize('viewAny', Exam::class);

            $exams = Exam::where('active', 'true')->get();

            return response()->json($exams);
        } catch (AuthorizationException $e) {
            Log::warning('Unauthorized access to exams index', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Non autorizzato'], 403);
        } catch (Exception $e) {
            Log::error('Error fetching exams', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Errore interno'], 500);
        }
    }

    /**
     * GET SINGLE
     */
    public function show(Exam $exam)
    {
        try {
            $this->authorize('view', $exam);

            return response()->json($exam);
        } catch (AuthorizationException $e) {
            Log::warning('Unauthorized access to exam', [
                'exam_id' => $exam->id,
                'error' => $e->getMessage()
            ]);
            return response()->json(['message' => 'Non autorizzato'], 403);
        } catch (Exception $e) {
            Log::error('Error fetching exam', [
                'exam_id' => $exam->id ?? null,
                'error' => $e->getMessage()
            ]);
            return response()->json(['message' => 'Errore interno'], 500);
        }
    }

    /**
     * CREATE
     */
    public function store(Request $request)
    {
        try {
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

        } catch (AuthorizationException $e) {
            Log::warning('Unauthorized exam creation attempt', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Non autorizzato'], 403);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validation failed on exam creation', [
                'errors' => $e->errors()
            ]);
            return response()->json([
                'message' => 'Errore di validazione',
                'errors' => $e->errors()
            ], 422);

        } catch (Exception $e) {
            Log::error('Error creating exam', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Errore interno'], 500);
        }
    }

    /**
     * UPDATE
     */
    public function update(Request $request, Exam $exam)
    {
        try {
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

        } catch (AuthorizationException $e) {
            Log::warning('Unauthorized exam update attempt', [
                'exam_id' => $exam->id,
                'error' => $e->getMessage()
            ]);
            return response()->json(['message' => 'Non autorizzato'], 403);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validation failed on exam update', [
                'exam_id' => $exam->id,
                'errors' => $e->errors()
            ]);
            return response()->json([
                'message' => 'Errore di validazione',
                'errors' => $e->errors()
            ], 422);

        } catch (Exception $e) {
            Log::error('Error updating exam', [
                'exam_id' => $exam->id ?? null,
                'error' => $e->getMessage()
            ]);
            return response()->json(['message' => 'Errore interno'], 500);
        }
    }

    /**
     * DELETE (soft via active = false)
     */
    public function destroy(Exam $exam)
    {
        try {
            $this->authorize('delete', $exam);

            $exam->update([
                'active' => "false"
            ]);

            return response()->json([
                'message' => 'Exam disattivato'
            ]);

        } catch (AuthorizationException $e) {
            Log::warning('Unauthorized exam delete attempt', [
                'exam_id' => $exam->id,
                'error' => $e->getMessage()
            ]);
            return response()->json(['message' => 'Non autorizzato'], 403);

        } catch (Exception $e) {
            Log::error('Error deleting exam', [
                'exam_id' => $exam->id ?? null,
                'error' => $e->getMessage()
            ]);
            return response()->json(['message' => 'Errore interno'], 500);
        }
    }
}
