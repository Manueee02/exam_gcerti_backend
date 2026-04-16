<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\PlannedExam;
use App\Services\ExaminerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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
        try {
            $plannedExams = PlannedExam::with([
                'exam',
                'testCenter',
            ])->get();
        } catch (\Exception $e) {
            Log::error('[PlannedExamController@index] Errore nel recupero delle sessioni d\'esame', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Errore nel recupero delle sessioni d\'esame'], 500);
        }

        try {
            $examinersResponse = $this->examinerService->getExaminers([
                'type'   => 'examiner',
                'status' => 'qualificato',
            ]);

            $decisionMakersResponse = $this->examinerService->getExaminers([
                'type'   => 'decision_maker',
                'status' => 'qualificato',
            ]);
        } catch (\Exception $e) {
            Log::error('[PlannedExamController@index] Errore nel recupero di esaminatori/decision makers dal servizio esterno', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Errore nel recupero degli esaminatori'], 500);
        }

        $examiners     = collect($examinersResponse['data']['data'] ?? [])->keyBy('id');
        $decisionMakers = collect($decisionMakersResponse['data']['data'] ?? [])->keyBy('id');

        $result = $plannedExams->map(function ($plannedExam) use ($examiners, $decisionMakers) {
            $exam          = $plannedExam->exam;
            $examiner      = $examiners->get($plannedExam->id_examiner);
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
                    ? ['public_id' => $examiner['public_id'] ?? null]
                    : null,
                'decision_maker' => $decisionMaker
                    ? ['public_id' => $decisionMaker['public_id'] ?? null]
                    : null,
            ];
        });

        return response()->json($result);
    }


    // GET /api/planned-exams/{publicId}
    public function show(string $publicId)
    {
        try {
            $plannedExam = PlannedExam::with([
                'exam',
                'testCenter',
                'candidateExams',
                'candidateExams.candidate',
                'inscriptions',
                'inscriptions.candidate',
            ])->where('public_id', $publicId)->first();
        } catch (\Exception $e) {
            Log::error('[PlannedExamController@show] Errore nel recupero della sessione d\'esame', [
                'public_id' => $publicId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Errore nel recupero della sessione d\'esame'], 500);
        }

        if (!$plannedExam) {
            return response()->json(['message' => 'Sessione d\'esame non trovata'], 404);
        }

        $user = Auth::user()?->load('role');
        $isAdmin = $user && in_array($user->role?->name, ['superAdmin', 'admin']);

        // =========================
        // ESAMINATORE
        // =========================
        try {
            $examinerResponse = $plannedExam->id_examiner
                ? $this->examinerService->getExaminer($plannedExam->id_examiner)
                : null;
        } catch (\Exception $e) {
            Log::error('[PlannedExamController@show] Errore nel recupero dell\'esaminatore', [
                'public_id'   => $publicId,
                'id_examiner' => $plannedExam->id_examiner,
                'error'       => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);
            $examinerResponse = null;
        }

        // =========================
        // DECISION MAKER (solo admin)
        // =========================
        $decisionMakerResponse = null;

        if ($isAdmin) {
            try {
                $decisionMakerResponse = $plannedExam->id_decision_maker
                    ? $this->examinerService->getExaminer($plannedExam->id_decision_maker)
                    : null;
            } catch (\Exception $e) {
                Log::error('[PlannedExamController@show] Errore nel recupero del decision maker', [
                    'public_id'         => $publicId,
                    'id_decision_maker' => $plannedExam->id_decision_maker,
                    'error'             => $e->getMessage(),
                    'trace'             => $e->getTraceAsString(),
                ]);
            }
        }

        // =========================
        // MAPPATURA PERSONA
        // =========================
        $mapPerson = function ($response) {
            if (empty($response['data']['data']['auditor'])) return null;

            $p = $response['data']['data']['auditor'];

            return [
                'public_id' => $p['public_id'] ?? null,
                'name'      => $p['name'] ?? null,
                'surname'   => $p['surname'] ?? null,
                'full_name' => trim(($p['name'] ?? '') . ' ' . ($p['surname'] ?? '')),
            ];
        };

        $data = $plannedExam->toArray();
        $data['examiner'] = $mapPerson($examinerResponse);
        $data['decision_maker'] = $mapPerson($decisionMakerResponse);

        // =========================
        // LOGICA USER vs ADMIN
        // =========================
        if (!$isAdmin) {
            // ❌ USER → rimuovi dati candidati
            unset($data['candidate_exams']);
            unset($data['inscriptions']);

            $alreadyEnrolled = false;
            $alreadyEnrolledSameExamType = false;

            if ($user?->candidate) {
                try {
                    $candidateId = $user->candidate->id;

                    // ✔ Iscritto a QUESTA sessione
                    $alreadyEnrolled = $plannedExam->inscriptions()
                        ->where('id_candidate', $candidateId)
                        ->whereNotIn('status', ['revoked', 'retired'])
                        ->exists();

                    // ✔ Iscritto a stessa TIPOLOGIA ESAME
                    $alreadyEnrolledSameExamType = \App\Models\PlannedExamInscription::where('id_candidate', $candidateId)
                        ->whereHas('plannedExam', function ($q) use ($plannedExam) {
                            $q->where('id_exam', $plannedExam->id_exam);
                        })
                        ->whereNotIn('status', ['revoked', 'retired'])
                        ->exists();

                } catch (\Exception $e) {
                    Log::error('[PlannedExamController@show] Errore nel controllo iscrizione candidato', [
                        'public_id' => $publicId,
                        'user_id'   => $user->id,
                        'error'     => $e->getMessage(),
                        'trace'     => $e->getTraceAsString(),
                    ]);
                }
            }

            $data['already_enrolled'] = $alreadyEnrolled;
            $data['already_enrolled_same_exam_type'] = $alreadyEnrolledSameExamType;
        }

        // ✅ ADMIN → mantiene tutto (candidate_exams inclusi)

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

        try {
            $exam = Exam::where('public_id', $validated['id_exam'])->first();
            if (!$exam) {
                return response()->json(['message' => 'Esame non trovato'], 404);
            }
        } catch (\Exception $e) {
            Log::error('[PlannedExamController@store] Errore nel recupero dell\'esame', [
                'id_exam' => $validated['id_exam'],
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Errore nel recupero dell\'esame'], 500);
        }

        try {
            $examinerData      = $this->examinerService->getExaminer($validated['id_examiner']);
            $decisionMakerData = $this->examinerService->getExaminer($validated['id_decision_maker']);
        } catch (\Exception $e) {
            Log::error('[PlannedExamController@store] Errore nel recupero di esaminatore/decision maker', [
                'id_examiner'       => $validated['id_examiner'],
                'id_decision_maker' => $validated['id_decision_maker'],
                'error'             => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Errore nel recupero dell\'esaminatore o del decision maker'], 500);
        }

        $examinerId      = $examinerData['data']['data']['auditor']['id']      ?? null;
        $decisionMakerId = $decisionMakerData['data']['data']['auditor']['id'] ?? null;

        if (!$examinerId || !$decisionMakerId) {
            Log::warning('[PlannedExamController@store] Esaminatore o decision maker non trovato nei dati restituiti', [
                'id_examiner'       => $validated['id_examiner'],
                'id_decision_maker' => $validated['id_decision_maker'],
            ]);
            return response()->json(['message' => 'Esaminatore o decision maker non trovato'], 404);
        }

        try {
            $plannedExam = PlannedExam::create([
                'id_exam'           => $exam->id,
                'id_examiner'       => $examinerId,
                'id_decision_maker' => $decisionMakerId,
                'date'              => $validated['date'],
                'time'              => $validated['time'],
                'end_time'          => $validated['end_time'],
            ]);
        } catch (\Exception $e) {
            Log::error('[PlannedExamController@store] Errore nella creazione della sessione d\'esame', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Errore nella creazione della sessione d\'esame'], 500);
        }

        return response()->json([
            'message' => 'Sessione d\'esame creata',
            'data'    => $plannedExam,
        ], 201);
    }


    // PUT /api/planned-exams/{publicId}
    public function update(Request $request, string $publicId)
    {
        try {
            $plannedExam = PlannedExam::where('public_id', $publicId)->first();
        } catch (\Exception $e) {
            Log::error('[PlannedExamController@update] Errore nel recupero della sessione d\'esame', [
                'public_id' => $publicId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Errore nel recupero della sessione d\'esame'], 500);
        }

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
            try {
                $exam = Exam::where('public_id', $validated['id_exam'])->first();
                if (!$exam) return response()->json(['message' => 'Esame non trovato'], 404);
                $toUpdate['id_exam'] = $exam->id;
            } catch (\Exception $e) {
                Log::error('[PlannedExamController@update] Errore nel recupero dell\'esame', [
                    'id_exam' => $validated['id_exam'],
                    'error'   => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ]);
                return response()->json(['message' => 'Errore nel recupero dell\'esame'], 500);
            }
        }

        if (isset($validated['id_examiner'])) {
            try {
                $data = $this->examinerService->getExaminer($validated['id_examiner']);
                $id   = $data['data']['data']['auditor']['id'] ?? null;
                if (!$id) return response()->json(['message' => 'Esaminatore non trovato'], 404);
                $toUpdate['id_examiner'] = $id;
            } catch (\Exception $e) {
                Log::error('[PlannedExamController@update] Errore nel recupero dell\'esaminatore', [
                    'id_examiner' => $validated['id_examiner'],
                    'error'       => $e->getMessage(),
                    'trace'       => $e->getTraceAsString(),
                ]);
                return response()->json(['message' => 'Errore nel recupero dell\'esaminatore'], 500);
            }
        }

        if (isset($validated['id_decision_maker'])) {
            try {
                $data = $this->examinerService->getExaminer($validated['id_decision_maker']);
                $id   = $data['data']['data']['auditor']['id'] ?? null;
                if (!$id) return response()->json(['message' => 'Decision maker non trovato'], 404);
                $toUpdate['id_decision_maker'] = $id;
            } catch (\Exception $e) {
                Log::error('[PlannedExamController@update] Errore nel recupero del decision maker', [
                    'id_decision_maker' => $validated['id_decision_maker'],
                    'error'             => $e->getMessage(),
                    'trace'             => $e->getTraceAsString(),
                ]);
                return response()->json(['message' => 'Errore nel recupero del decision maker'], 500);
            }
        }

        try {
            $plannedExam->update($toUpdate);
        } catch (\Exception $e) {
            Log::error('[PlannedExamController@update] Errore nell\'aggiornamento della sessione d\'esame', [
                'public_id' => $publicId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Errore nell\'aggiornamento della sessione d\'esame'], 500);
        }

        return response()->json([
            'message' => 'Sessione d\'esame aggiornata',
            'data'    => $plannedExam,
        ]);
    }


    // DELETE /api/planned-exams/{publicId}
    public function destroy(string $publicId)
    {
        try {
            $plannedExam = PlannedExam::where('public_id', $publicId)->first();
        } catch (\Exception $e) {
            Log::error('[PlannedExamController@destroy] Errore nel recupero della sessione d\'esame', [
                'public_id' => $publicId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Errore nel recupero della sessione d\'esame'], 500);
        }

        if (!$plannedExam) {
            return response()->json(['message' => 'Sessione d\'esame non trovata'], 404);
        }

        try {
            if ($plannedExam->candidateExams()->exists()) {
                return response()->json([
                    'message' => 'Impossibile eliminare la sessione: ci sono candidati iscritti',
                ], 400);
            }

            $plannedExam->delete();
        } catch (\Exception $e) {
            Log::error('[PlannedExamController@destroy] Errore nell\'eliminazione della sessione d\'esame', [
                'public_id' => $publicId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Errore nell\'eliminazione della sessione d\'esame'], 500);
        }

        return response()->json(['message' => 'Sessione d\'esame eliminata']);
    }


    // GET /api/planned-exams/reference-data
    public function referenceData()
    {
        try {
            $exams = Exam::all()
                ->map(fn($e) => [
                    'public_id'   => $e->public_id,
                    'name'        => $e->name,
                    'description' => $e->description,
                    'type'        => $e->type,
                    'cost'        => $e->cost,
                    'color'       => $e->color,
                    'active'      => $e->active,
                    'created_at'  => $e->created_at,
                    'updated_at'  => $e->updated_at,
                ])
                ->values();
        } catch (\Exception $e) {
            Log::error('[PlannedExamController@referenceData] Errore nel recupero degli esami', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Errore nel recupero degli esami'], 500);
        }

        try {
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
        } catch (\Exception $e) {
            Log::error('[PlannedExamController@referenceData] Errore nel recupero degli esaminatori', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Errore nel recupero degli esaminatori'], 500);
        }

        try {
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
        } catch (\Exception $e) {
            Log::error('[PlannedExamController@referenceData] Errore nel recupero dei decision makers', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Errore nel recupero dei decision makers'], 500);
        }

        return response()->json([
            'exams'           => $exams,
            'examiners'       => $examiners,
            'decision_makers' => $decisionMakers,
        ]);
    }
}
