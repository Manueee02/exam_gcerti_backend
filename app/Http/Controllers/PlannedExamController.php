<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\PlannedExam;
use App\Services\ExaminerService;
use Illuminate\Http\Request;

class PlannedExamController extends Controller
{
    protected ExaminerService $examinerService;

    public function __construct(ExaminerService $examinerService)
    {
        $this->examinerService = $examinerService;
    }

     private function cleanDateTime(string $value, string $type = 'date'): string {
        // Controlla se è un valore valido
        if (empty($value)) return '';

        $timestamp = strtotime($value);

        if ($type === 'date') {
            return date('Y-m-d', $timestamp); // formato 2026-03-12
        } elseif ($type === 'time') {
            return date('H:i', $timestamp);   // formato 16:00
        }

        return $value;
    }


    // GET /api/planned-exams
    public function index()
    {
        $plannedExams = PlannedExam::with([
            'exam',
            'testCenter',
        ])->get();

        // Fetch esaminatori e decision maker dal service esterno
        $examinersResponse = $this->examinerService->getExaminers([
            'type'   => 'examiner',
            'status' => 'qualificato',
        ]);

        $decisionMakersResponse = $this->examinerService->getExaminers([
            'type'   => 'decision_maker',
            'status' => 'qualificato',
        ]);

        $examiners = collect($examinersResponse['data'] ?? [])
            ->keyBy('id');

/*        $decisionMakers = collect($decisionMakersResponse['data'] ?? [])
            ->keyBy('id');*/

        $result = $plannedExams->map(function ($plannedExam) use ($examiners/*, $decisionMakers*/) {

            $exam = $plannedExam->exam;

            $examiner = $examiners->get($plannedExam->id_examiner);
/*            $decisionMaker = $decisionMakers->get($plannedExam->id_decision_maker);*/

            return [
                'id' => $plannedExam->id,
                'title' => $exam?->name,
                'date' =>  $this->cleanDateTime($plannedExam->date, 'date'),
                'time' =>  $this->cleanDateTime($plannedExam->time, 'time'),
                'end_time' =>  $this->cleanDateTime($plannedExam->end_time, 'time'),

                'color' => $exam?->color,
                'cost' => $exam?->cost,
                'tag' => $exam?->type,

                /*'attendees' => collect([
                    $examiner,
                    $decisionMaker
                ])->filter()->map(function ($person) {
                    return [
                        'name' => $person['name'] . ' ' . $person['surname'],
                        'avatar' => strtoupper(substr($person['name'],0,1) . substr($person['surname'],0,1)),
                        'color' => '#' . substr(md5($person['id']),0,6)
                    ];
                })->values(),*/

                'location' => $plannedExam->location,
                'description' => $exam?->description,

                'organizer' => $examiner
                    ? $examiner['name'] . ' ' . $examiner['surname']
                    : null,
                'id_exam' => $exam?->id,
                'id_examiner'=> $plannedExam->id_examiner,
                'id_decision_maker'=> $plannedExam->id_decision_maker,
            ];
        });

        return response()->json($result);
    }


    // GET /api/planned-exams/{id}
    public function show($id)
    {
        $plannedExam = PlannedExam::with([
            'exam',
            'testCenter',
            'candidateExams'
        ])->find($id);

        if (!$plannedExam) {
            return response()->json([
                'message' => 'Sessione d\'esame non trovata'
            ], 404);
        }

        // Fetch esaminatore e decision maker tramite ID
        $examinerResponse     = $this->examinerService->getExaminer($plannedExam->id_examiner);
        $decisionMakerResponse = $this->examinerService->getExaminer($plannedExam->id_decision_maker);

        $examiner = null;
        if (isset($examinerResponse['data'])) {
            $e = $examinerResponse['data'];
            $examiner = ['nome' => $e['nome'], 'cognome' => $e['cognome']];
        }

        $decisionMaker = null;
        if (isset($decisionMakerResponse['data'])) {
            $d = $decisionMakerResponse['data'];
            $decisionMaker = ['nome' => $d['nome'], 'cognome' => $d['cognome']];
        }

        $data                  = $plannedExam->toArray();
        $data['examiner']      = $examiner;
        $data['decision_maker'] = $decisionMaker;

        return response()->json($data);
    }


    // POST /api/planned-exams
    public function store(Request $request)
    {
        $validated = $request->validate([
            'id_exam'           => 'required|integer',
            'id_examiner'       => 'required|integer',
            'id_decision_maker' => 'required|integer',
            'date'              => 'required|date',
            'time'              => 'required',
            'end_time'              => 'required'
        ]);

        $plannedExam = PlannedExam::create($validated);

        return response()->json([
            'message' => 'Sessione d\'esame creata',
            'data'    => $plannedExam
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
            'id_exam'           => 'sometimes|integer',
            'id_test_center'    => 'sometimes|integer',
            'id_examiner'       => 'sometimes|integer',
            'id_decision_maker' => 'sometimes|integer',
            'date'              => 'sometimes|date',
            'time'              => 'sometimes'
        ]);

        $plannedExam->update($validated);

        return response()->json([
            'message' => 'Sessione d\'esame aggiornata',
            'data'    => $plannedExam
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

    // GET /api/planned-exams/reference-data
    public function referenceData()
    {
        // Esami dal DB locale
        $exams = Exam::all();

        // Esaminatori dal service esterno
        $examinersResponse = $this->examinerService->getExaminers([
            'type'   => 'examiner',
            'status' => 'qualificato',
        ]);

        $examiners = collect($examinersResponse['data']['data'] ?? [])
            ->map(fn($e) => [
                'id'      => $e['id'],
                'name'    => $e['name'],
                'surname' => $e['surname'],
            ])
            ->values();

        // Decision maker dal service esterno
        $decisionMakersResponse = $this->examinerService->getExaminers([
            'type'   => 'decision_maker',
            'status' => 'qualificato',
        ]);

        $decisionMakers = collect($decisionMakersResponse['data']['data'] ?? [])
            ->map(fn($e) => [
                'id'      => $e['id'],
                'name'    => $e['name'],
                'surname' => $e['surname'],
            ])
            ->values();

        return response()->json([
            'exams'          => $exams,
            'examiners'      => $examiners,
            'decision_makers' => $decisionMakers,
        ]);
    }
}
