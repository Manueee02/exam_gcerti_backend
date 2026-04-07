<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\PlannedExam;
use App\Services\ExaminerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PlannedExamController extends Controller
{
    protected ExaminerService $examinerService;

    public function __construct(ExaminerService $examinerService)
    {
        $this->examinerService = $examinerService;
    }

    private function cleanDateTime(string $value, string $type = 'date'): string
    {
        if (empty($value)) return '';

        $timestamp = strtotime($value);

        if ($type === 'date') {
            return date('Y-m-d', $timestamp);
        } elseif ($type === 'time') {
            return date('H:i', $timestamp);
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

        $examinersResponse = $this->examinerService->getExaminers([
            'type'   => 'examiner',
            'status' => 'qualificato',
        ]);

        $decisionMakersResponse = $this->examinerService->getExaminers([
            'type'   => 'decision_maker',
            'status' => 'qualificato',
        ]);

        $examiners = collect($examinersResponse['data']['data'] ?? [])
            ->keyBy('id');

        $decisionMakers = collect($decisionMakersResponse['data']['data'] ?? [])
            ->keyBy('id');

        $result = $plannedExams->map(function ($plannedExam) use ($examiners, $decisionMakers) {
            $exam     = $plannedExam->exam;
            $examiner = $examiners->get($plannedExam->id_examiner);
            $decisionMaker = $decisionMakers->get($plannedExam->id_decision_maker);

            return [
                'public_id'   => $plannedExam->public_id,
                'title'       => $exam?->name,
                'date'        => $this->cleanDateTime($plannedExam->date, 'date'),
                'time'        => $this->cleanDateTime($plannedExam->time, 'time'),
                'end_time'    => $this->cleanDateTime($plannedExam->end_time, 'time'),
                'color'       => $exam?->color,
                'cost'        => $exam?->cost,
                'tag'         => $exam?->type,
                'location'    => $plannedExam->location,
                'description' => $exam?->description,
                'organizer'   => $examiner
                    ? [
                        'name'      => $examiner['name'] . ' ' . $examiner['surname'],
                        'public_id' => $examiner['public_id'] ?? null,
                    ]
                    : null,
                'exam' => [
                    'public_id' => $exam?->public_id ?? null,
                ],
                'examiner' => $examiner
                    ? [
                        'public_id' => $examiner['public_id'] ?? null,
                    ]
                    : null,

                'decision_maker' => $decisionMaker
                    ? [
                        'public_id' => $decisionMaker['public_id'] ?? null,
                    ]
                    : null,
            ];
        });

        return response()->json($result);
    }


    // GET /api/planned-exams/{publicId}
    public function show(string $publicId)
    {
        $plannedExam = PlannedExam::with([
            'exam',
            'testCenter',
            'candidateExams',
        ])->where('public_id', $publicId)->first();

        if (!$plannedExam) {
            return response()->json([
                'message' => 'Sessione d\'esame non trovata',
            ], 404);
        }

        $user = Auth::user()?->load('role');

        $examinerResponse = $plannedExam->id_examiner
            ? $this->examinerService->getExaminer($plannedExam->id_examiner)
            : null;

        $decisionMakerResponse = null;
        if ($user && in_array($user->role?->name, ['superAdmin', 'admin'])) {
            $decisionMakerResponse = $plannedExam->id_decision_maker
                ? $this->examinerService->getExaminer($plannedExam->id_decision_maker)
                : null;
        }

        $mapPerson = function ($response) {
            if (empty($response['data']['data']['auditor'])) return null;

            $p = $response['data']['data']['auditor'];

            return [
                'public_id'        => $p['public_id'] ?? null,
                'name'      => $p['name'] ?? null,
                'surname'   => $p['surname'] ?? null,
                'full_name' => trim(($p['name'] ?? '') . ' ' . ($p['surname'] ?? '')),
            ];
        };

        $data                   = $plannedExam->toArray();
        $data['examiner']       = $mapPerson($examinerResponse);
        $data['decision_maker'] = $mapPerson($decisionMakerResponse);

        return response()->json($data);
    }


    // POST /api/planned-exams
    public function store(Request $request)
    {
        $validated = $request->validate([
            'id_exam'           => 'required|string',
            'id_examiner'       => 'required|string',
            'id_decision_maker' => 'required|string',
            'date'              => 'required|date',
            'time'              => 'required',
            'end_time'          => 'required',
        ]);

        // Risolvi public_id → id reale per l'esame
        $exam = Exam::where('public_id', $validated['id_exam'])->first();
        if (!$exam) {
            return response()->json(['message' => 'Esame non trovato'], 404);
        }

        // Per examiner e decision_maker chiami il servizio esterno
        $examinerData       = $this->examinerService->getExaminer($validated['id_examiner']);
        $decisionMakerData  = $this->examinerService->getExaminer($validated['id_decision_maker']);

        $examinerId       = $examinerData['data']['data']['auditor']['id']       ?? null;
        $decisionMakerId  = $decisionMakerData['data']['data']['auditor']['id']  ?? null;

        if (!$examinerId || !$decisionMakerId) {
            return response()->json(['message' => 'Esaminatore o decision maker non trovato'], 404);
        }

        $plannedExam = PlannedExam::create([
            'id_exam'           => $exam->id,
            'id_examiner'       => $examinerId,
            'id_decision_maker' => $decisionMakerId,
            'date'              => $validated['date'],
            'time'              => $validated['time'],
            'end_time'          => $validated['end_time'],
        ]);

        return response()->json([
            'message' => 'Sessione d\'esame creata',
            'data'    => $plannedExam,
        ], 201);
    }


    // PUT /api/planned-exams/{publicId}
    public function update(Request $request, string $publicId)
    {
        $plannedExam = PlannedExam::where('public_id', $publicId)->first();

        if (!$plannedExam) {
            return response()->json(['message' => 'Sessione d\'esame non trovata'], 404);
        }

        $validated = $request->validate([
            'id_exam'           => 'sometimes|string',
            'id_examiner'       => 'sometimes|string',
            'id_decision_maker' => 'sometimes|string',
            'date'              => 'sometimes|date',
            'time'              => 'sometimes',
            'end_time'          => 'sometimes',
        ]);

        $toUpdate = array_filter([
            'date'     => $validated['date']     ?? null,
            'time'     => $validated['time']     ?? null,
            'end_time' => $validated['end_time'] ?? null,
        ], fn($v) => $v !== null);

        if (isset($validated['id_exam'])) {
            $exam = Exam::where('public_id', $validated['id_exam'])->first();
            if (!$exam) return response()->json(['message' => 'Esame non trovato'], 404);
            $toUpdate['id_exam'] = $exam->id;
        }

        if (isset($validated['id_examiner'])) {
            $data = $this->examinerService->getExaminer($validated['id_examiner']);
            $id   = $data['data']['data']['auditor']['id'] ?? null;
            if (!$id) return response()->json(['message' => 'Esaminatore non trovato'], 404);
            $toUpdate['id_examiner'] = $id;
        }

        if (isset($validated['id_decision_maker'])) {
            $data = $this->examinerService->getExaminer($validated['id_decision_maker']);
            $id   = $data['data']['data']['auditor']['id'] ?? null;
            if (!$id) return response()->json(['message' => 'Decision maker non trovato'], 404);
            $toUpdate['id_decision_maker'] = $id;
        }

        $plannedExam->update($toUpdate);

        return response()->json([
            'message' => 'Sessione d\'esame aggiornata',
            'data'    => $plannedExam,
        ]);
    }


    // DELETE /api/planned-exams/{publicId}
    public function destroy(string $publicId)
    {
        $plannedExam = PlannedExam::where('public_id', $publicId)->first();

        if (!$plannedExam) {
            return response()->json([
                'message' => 'Sessione d\'esame non trovata',
            ], 404);
        }

        if ($plannedExam->candidateExams()->exists()) {
            return response()->json([
                'message' => 'Impossibile eliminare la sessione: ci sono candidati iscritti',
            ], 400);
        }

        $plannedExam->delete();

        return response()->json([
            'message' => 'Sessione d\'esame eliminata',
        ]);
    }


    // GET /api/planned-exams/reference-data
    public function referenceData()
    {
        $exams = Exam::all()
            ->map(fn($e) => [
                'public_id'  => $e->public_id,
                'name'       => $e->name,
                'description'=> $e->description,
                'type'       => $e->type,
                'cost'       => $e->cost,
                'color'      => $e->color,
                'active'     => $e->active,
                'created_at' => $e->created_at,
                'updated_at' => $e->updated_at,
            ])
            ->values();

        $examinersResponse = $this->examinerService->getExaminers([
            'type'   => 'examiner',
            'status' => 'qualificato',
        ]);

        $examiners = collect($examinersResponse['data']['data'] ?? [])
            ->map(fn($e) => [
                'public_id' => $e['public_id'] ?? null,
                'name'      => $e['name'],
                'surname'   => $e['surname'],
            ])
            ->values();

        $decisionMakersResponse = $this->examinerService->getExaminers([
            'type'   => 'decision_maker',
            'status' => 'qualificato',
        ]);

        $decisionMakers = collect($decisionMakersResponse['data']['data'] ?? [])
            ->map(fn($e) => [
                'public_id' => $e['public_id'] ?? null,
                'name'      => $e['name'],
                'surname'   => $e['surname'],
            ])
            ->values();

        return response()->json([
            'exams'           => $exams,
            'examiners'       => $examiners,
            'decision_makers' => $decisionMakers,
        ]);
    }
}
