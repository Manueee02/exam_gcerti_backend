<?php

namespace App\Http\Controllers;

use App\Models\PlannedExam;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PlannedExamController extends Controller
{

    // GET /api/planned-exams
    public function index()
    {
        $plannedExams = PlannedExam::with([
            'exam',
            'testCenter',
            'examiner',
            'decisionMaker'
        ])->get();

        return response()->json($plannedExams);
    }


    // GET /api/planned-exams/{id}
    public function show($id)
    {
        $plannedExam = PlannedExam::with([
            'exam',
            'testCenter',
            'decisionMaker',
            'candidateExams'
        ])->find($id);

        $examiner = $this->getExaminer($plannedExam->id_examiner);

        if (!$plannedExam) {
            return response()->json([
                'message' => 'Sessione d\'esame non trovata'
            ], 404);
        }

        return response()->json($plannedExam);
    }


    // POST /api/planned-exams
    public function store(Request $request)
    {
        $validated = $request->validate([
            'id_exam' => 'required|integer',
            'id_test_center' => 'required|integer',
            'id_examiner' => 'required|integer',
            'id_decision_maker' => 'required|integer',
            'date' => 'required|date',
            'time' => 'required'
        ]);

        $plannedExam = PlannedExam::create($validated);

        return response()->json([
            'message' => 'Sessione d\'esame creata',
            'data' => $plannedExam
        ], 201);
    }


    // PUT /api/planned-exams/{id}
    public function update(Request $request, $id)
    {
        $plannedExam = PlannedExam::find($id);

        if (!$plannedExam) {
            return response()->json([
                'message' => 'Sessione d\'esame non trovata'
            ], 404);
        }

        $validated = $request->validate([
            'id_exam' => 'sometimes|integer',
            'id_test_center' => 'sometimes|integer',
            'id_examiner' => 'sometimes|integer',
            'id_decision_maker' => 'sometimes|integer',
            'date' => 'sometimes|date',
            'time' => 'sometimes'
        ]);

        $plannedExam->update($validated);

        return response()->json([
            'message' => 'Sessione d\'esame aggiornata',
            'data' => $plannedExam
        ]);
    }


    // DELETE /api/planned-exams/{id}
    public function destroy($id)
    {
        $plannedExam = PlannedExam::find($id);

        if (!$plannedExam) {
            return response()->json([
                'message' => 'Sessione d\'esame non trovata'
            ], 404);
        }

        // controllo candidati iscritti
        if ($plannedExam->candidateExams()->exists()) {
            return response()->json([
                'message' => 'Impossibile eliminare la sessione: ci sono candidati iscritti'
            ], 400);
        }

        $plannedExam->delete();

        return response()->json([
            'message' => 'Sessione d\'esame eliminata'
        ]);
    }

    public function getExaminer($id)
    {
        $response = Http::withToken(config('services.app1.token'))
            ->get(config('services.app1.url') . '/examiner/' . $id);

        return $response->successful() ? $response->json() : null;
    }

}
