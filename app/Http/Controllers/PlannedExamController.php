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

        // Recupera candidato dell'utente corrente (solo ruolo 'user')
        $currentUser = Auth::user();
        $userCandidateId = null;
        if ($currentUser) {
            $currentUser->load('role', 'candidate');
            if ($currentUser->role?->name === 'user' && $currentUser->candidate) {
                $userCandidateId = $currentUser->candidate->id;
            }
        }

        if ($userCandidateId) {
            $plannedExams->load(['inscriptions' => function ($q) use ($userCandidateId) {
                $q->where('id_candidate', $userCandidateId)
                    ->whereNotIn('status', ['revoked', 'retired']);
            }]);
        }

        // Raccogli gli ID univoci presenti negli esami pianificati
        $examinerIds      = $plannedExams->pluck('id_examiner')->filter()->unique();
        $decisionMakerIds = $plannedExams->pluck('id_decision_maker')->filter()->unique();

        // Fetch individuale per ogni esaminatore unico (evita problemi di paginazione)
        $examiners = collect();
        foreach ($examinerIds as $exId) {
            try {
                $resp = $this->examinerService->getExaminer($exId);
                if (!empty($resp['data']['data']['auditor'])) {
                    $examiners->put($exId, $resp['data']['data']['auditor']);
                }
            } catch (\Exception $e) {
                Log::warning('[PlannedExamController@index] Impossibile recuperare esaminatore', [
                    'id_examiner' => $exId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fetch individuale per ogni decision maker unico
        $decisionMakers = collect();
        foreach ($decisionMakerIds as $dmId) {
            try {
                $resp = $this->examinerService->getExaminer($dmId);
                if (!empty($resp['data']['data']['auditor'])) {
                    $decisionMakers->put($dmId, $resp['data']['data']['auditor']);
                }
            } catch (\Exception $e) {
                Log::warning('[PlannedExamController@index] Impossibile recuperare decision maker', [
                    'id_decision_maker' => $dmId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $result = $plannedExams->map(function ($plannedExam) use ($examiners, $decisionMakers, $userCandidateId) {
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
                'already_enrolled' => $userCandidateId !== null
                    ? $plannedExam->inscriptions->isNotEmpty()
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

        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        if ($user) {
            $user->load('role');
        }
        $userRole = $user?->role?->name ?? null;
        $isAdmin = $user && in_array($userRole, ['superAdmin', 'admin']);

        // =========================
        // VERIFICA AUTORIZZAZIONE EXAMINER/DM
        // =========================
        $isExaminerOrDM = false;
        $isAuthorizedForThisExam = false;

        if ($userRole === 'examiner' && $user) {
            $examiner = \App\Models\Examiner::where('id_user', $user->id)->first();
            if ($examiner) {
                $isExaminerOrDM = true;
                try {
                    $examinerData = $this->examinerService->getExaminer($examiner->public_id);
                    $externalId   = $examinerData['data']['data']['auditor']['id'] ?? null;
                    $isAuthorizedForThisExam = $externalId && ($plannedExam->id_examiner == $externalId);
                } catch (\Exception $e) {
                    $isAuthorizedForThisExam = false;
                }
                Log::info('[PlannedExamController@show] Examiner verifica autorizzazione', [
                    'user_id' => $user->id,
                    'examiner_public_id' => $examiner->public_id,
                    'planned_exam_id_examiner' => $plannedExam->id_examiner,
                    'is_authorized' => $isAuthorizedForThisExam,
                ]);
            }
        } elseif ($userRole === 'decisionMaker' && $user) {
            $decisionMaker = \App\Models\DecisionsMaker::where('id_user', $user->id)->first();
            if ($decisionMaker) {
                $isExaminerOrDM = true;
                try {
                    $dmData     = $this->examinerService->getExaminer($decisionMaker->public_id);
                    $externalId = $dmData['data']['data']['auditor']['id'] ?? null;
                    $isAuthorizedForThisExam = $externalId && ($plannedExam->id_decision_maker == $externalId);
                } catch (\Exception $e) {
                    $isAuthorizedForThisExam = false;
                }
                Log::info('[PlannedExamController@show] Decision maker verifica autorizzazione', [
                    'user_id' => $user->id,
                    'dm_public_id' => $decisionMaker->public_id,
                    'planned_exam_id_decision_maker' => $plannedExam->id_decision_maker,
                    'is_authorized' => $isAuthorizedForThisExam,
                ]);
            }
        }

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
        // DECISION MAKER
        // =========================
        $decisionMakerResponse = null;

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
        // LOGICA USER vs ADMIN vs EXAMINER/DM
        // =========================
        if ($isAdmin || ($isExaminerOrDM && $isAuthorizedForThisExam)) {
            // Carica i candidati approvati dalla tabella planned_exams_candidates
            $plannedExam->load('plannedExamCandidates.candidate');

            $data['approved_candidates'] = $plannedExam->plannedExamCandidates->map(function ($pec) {
                $candidate = $pec->candidate;
                return [
                    'id' => $candidate->id,
                    'public_id' => $candidate->public_id ?? null,
                    'name' => $candidate->name,
                    'surname' => $candidate->surname,
                    'email' => $candidate->email,
                    'phone' => $candidate->phone,
                    'fiscal_code' => $candidate->fiscal_code,
                    'enrolled_at' => $pec->created_at?->format('Y-m-d H:i:s'),
                ];
            });

            // Per examiner/decisionMaker rimuovi dati sensibili (solo admin vede inscriptions)
            if (!$isAdmin) {
                unset($data['inscriptions']);
            }

            Log::info('[PlannedExamController@show] Candidati approvati inclusi nella risposta', [
                'public_id' => $publicId,
                'user_role' => $userRole,
                'is_admin' => $isAdmin,
                'is_authorized' => $isAuthorizedForThisExam,
                'candidates_count' => $plannedExam->plannedExamCandidates->count(),
            ]);
        } else {
            // ❌ USER normale O examiner/decisionMaker non autorizzato → rimuovi dati candidati
            unset($data['candidate_exams']);
            unset($data['inscriptions']);
            unset($data['planned_exam_candidates']);

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
                        'user_id'   => $user?->id,
                        'error'     => $e->getMessage(),
                        'trace'     => $e->getTraceAsString(),
                    ]);
                }
            }

            $data['already_enrolled'] = $alreadyEnrolled;
            $data['already_enrolled_same_exam_type'] = $alreadyEnrolledSameExamType;

            // Log per examiner/DM non autorizzato
            if ($isExaminerOrDM && !$isAuthorizedForThisExam) {
                Log::warning('[PlannedExamController@show] Examiner/DM non autorizzato per questo esame', [
                    'public_id' => $publicId,
                    'user_id' => $user?->id,
                    'user_role' => $userRole,
                ]);
            }
        }

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

            // Verifica che esistano GDPR attivi specifici per questo esame (entrambi i tipi)
            $inscriptionGdprExists = \App\Models\GDPR::where('type', 'inscription')
                ->whereNull('id_exam')
                ->whereHas('activeVersion')
                ->exists();

            if (!$inscriptionGdprExists) {
                return response()->json([
                    'message' => 'Impossibile creare la sessione: nessun GDPR attivo di tipo "inscription" configurato. Crea prima il GDPR necessario.',
                ], 422);
            }

            // Verifica GDPR tipo exam (deve esistere uno specifico per questo esame)
            $examGdprExists = \App\Models\GDPR::where('type', 'exam')
                ->where('id_exam', $exam->id)
                ->whereHas('activeVersion')
                ->exists();

            if (!$examGdprExists) {
                return response()->json([
                    'message' => "Impossibile creare la sessione per l'esame \"{$exam->name}\": nessun GDPR attivo configurato per questo esame. Crea prima il GDPR necessario.",
                ], 422);
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
// GET /api/planned-exams/reference-data
    public function referenceData()
    {
        $requestId = uniqid('refdata_', true);

        Log::info('[PlannedExamController@referenceData] START', [
            'request_id' => $requestId,
            'app1_url'   => config('services.app1.url'),
        ]);

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

            Log::info('[PlannedExamController@referenceData] Exams OK', [
                'request_id' => $requestId,
                'count'      => $exams->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('[PlannedExamController@referenceData] Errore nel recupero degli esami', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Errore nel recupero degli esami'], 500);
        }

        // =========================
        // EXAMINERS
        // =========================
        try {
            Log::info('[PlannedExamController@referenceData] Chiamata getExaminers (examiner) - INIZIO', [
                'request_id' => $requestId,
                'filters'    => ['type' => 'examiner', 'status' => 'qualificato'],
            ]);

            $examinersResponse = $this->examinerService->getExaminers([
                'type'   => 'examiner',
                'status' => 'qualificato',
            ]);

            // Log della risposta GREZZA, prima di qualunque trasformazione
            Log::info('[PlannedExamController@referenceData] Chiamata getExaminers (examiner) - RISPOSTA GREZZA', [
                'request_id' => $requestId,
                'status'     => $examinersResponse['status'] ?? null,
                'error'      => $examinersResponse['error'] ?? null,
                'data_type'  => gettype($examinersResponse['data'] ?? null),
                'data_keys'  => is_array($examinersResponse['data'] ?? null) ? array_keys($examinersResponse['data']) : null,
                'full_response' => $examinersResponse, // l'intero payload, per non perdere nulla
            ]);

            $rawExaminers = $examinersResponse['data']['data'] ?? [];

            Log::info('[PlannedExamController@referenceData] Examiners - prima del map', [
                'request_id'     => $requestId,
                'raw_count'      => is_countable($rawExaminers) ? count($rawExaminers) : 'NOT_COUNTABLE',
                'raw_first_item' => is_array($rawExaminers) ? ($rawExaminers[0] ?? null) : null,
            ]);

            $examiners = collect($rawExaminers)
                ->map(fn($e) => [
                    'public_id' => $e['public_id'] ?? null,
                    'name'      => $e['name'],
                    'surname'   => $e['surname'],
                ])
                ->values();

            Log::info('[PlannedExamController@referenceData] Examiners - dopo il map, OK', [
                'request_id' => $requestId,
                'count'      => $examiners->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('[PlannedExamController@referenceData] Errore nel recupero degli esaminatori', [
                'request_id' => $requestId,
                'error'      => $e->getMessage(),
                'class'      => get_class($e),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
                'trace'      => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Errore nel recupero degli esaminatori'], 500);
        }

        // =========================
        // DECISION MAKERS
        // =========================
        try {
            Log::info('[PlannedExamController@referenceData] Chiamata getExaminers (decision_maker) - INIZIO', [
                'request_id' => $requestId,
                'filters'    => ['type' => 'decision_maker', 'status' => 'qualificato'],
            ]);

            $decisionMakersResponse = $this->examinerService->getExaminers([
                'type'   => 'decision_maker',
                'status' => 'qualificato',
            ]);

            Log::info('[PlannedExamController@referenceData] Chiamata getExaminers (decision_maker) - RISPOSTA GREZZA', [
                'request_id'    => $requestId,
                'status'        => $decisionMakersResponse['status'] ?? null,
                'error'         => $decisionMakersResponse['error'] ?? null,
                'data_type'     => gettype($decisionMakersResponse['data'] ?? null),
                'data_keys'     => is_array($decisionMakersResponse['data'] ?? null) ? array_keys($decisionMakersResponse['data']) : null,
                'full_response' => $decisionMakersResponse,
            ]);

            $rawDecisionMakers = $decisionMakersResponse['data']['data'] ?? [];

            Log::info('[PlannedExamController@referenceData] DecisionMakers - prima del map', [
                'request_id'     => $requestId,
                'raw_count'      => is_countable($rawDecisionMakers) ? count($rawDecisionMakers) : 'NOT_COUNTABLE',
                'raw_first_item' => is_array($rawDecisionMakers) ? ($rawDecisionMakers[0] ?? null) : null,
            ]);

            $decisionMakers = collect($rawDecisionMakers)
                ->map(fn($e) => [
                    'public_id' => $e['public_id'] ?? null,
                    'name'      => $e['name'],
                    'surname'   => $e['surname'],
                ])
                ->values();

            Log::info('[PlannedExamController@referenceData] DecisionMakers - dopo il map, OK', [
                'request_id' => $requestId,
                'count'      => $decisionMakers->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('[PlannedExamController@referenceData] Errore nel recupero dei decision makers', [
                'request_id' => $requestId,
                'error'      => $e->getMessage(),
                'class'      => get_class($e),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
                'trace'      => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Errore nel recupero dei decision makers'], 500);
        }

        Log::info('[PlannedExamController@referenceData] END - successo', [
            'request_id'           => $requestId,
            'exams_count'          => $exams->count(),
            'examiners_count'      => $examiners->count(),
            'decision_makers_count' => $decisionMakers->count(),
        ]);

        return response()->json([
            'exams'           => $exams,
            'examiners'       => $examiners,
            'decision_makers' => $decisionMakers,
        ]);
    }

    /**
     * GET /api/my-exams
     * Restituisce gli esami assegnati all'utente loggato (examiner o decision maker)
     */
    public function myExams(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            Log::warning('[PlannedExamController@myExams] Tentativo di accesso senza autenticazione');
            return response()->json([
                'success' => false,
                'message' => 'Utente non autenticato'
            ], 401);
        }

        $userRole = $user->role?->name;

        try {
            $query = PlannedExam::with([
                'exam',
                'testCenter',
                'plannedExamCandidates.candidate',
            ]);

            // Filtra in base al ruolo
            if ($userRole === 'examiner') {
                $examiner = \App\Models\Examiner::where('id_user', $user->id)->first();

                if (!$examiner) {
                    return response()->json(['success' => false, 'message' => 'Profilo esaminatore non trovato'], 404);
                }

                // public_id locale corrisponde al public_id esterno → recupera l'id numerico esterno
                $examinerData = $this->examinerService->getExaminer($examiner->public_id);
                $externalId   = $examinerData['data']['data']['auditor']['id'] ?? null;

                if (!$externalId) {
                    return response()->json(['success' => false, 'message' => 'Esaminatore non trovato nel servizio esterno'], 404);
                }

                $query->where('id_examiner', $externalId);

                Log::info('[PlannedExamController@myExams] Recupero esami per esaminatore', [
                    'user_id' => $user->id,
                    'examiner_local_id' => $examiner->id,
                    'examiner_external_id' => $externalId,
                ]);
            } elseif ($userRole === 'decisionMaker') {
                $decisionMaker = \App\Models\DecisionsMaker::where('id_user', $user->id)->first();

                if (!$decisionMaker) {
                    return response()->json(['success' => false, 'message' => 'Profilo decision maker non trovato'], 404);
                }

                $dmData     = $this->examinerService->getExaminer($decisionMaker->public_id);
                $externalId = $dmData['data']['data']['auditor']['id'] ?? null;

                if (!$externalId) {
                    return response()->json(['success' => false, 'message' => 'Decision maker non trovato nel servizio esterno'], 404);
                }

                $query->where('id_decision_maker', $externalId);

                Log::info('[PlannedExamController@myExams] Recupero esami per decision maker', [
                    'user_id' => $user->id,
                    'dm_local_id' => $decisionMaker->id,
                    'dm_external_id' => $externalId,
                ]);
            } else {
                Log::warning('[PlannedExamController@myExams] Ruolo non autorizzato', [
                    'user_id' => $user->id,
                    'role' => $userRole,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Ruolo non autorizzato per questa operazione'
                ], 403);
            }

            $plannedExams = $query->orderBy('date', 'desc')->get();

            Log::info('[PlannedExamController@myExams] Esami recuperati con successo', [
                'user_id' => $user->id,
                'role' => $userRole,
                'count' => $plannedExams->count(),
            ]);

            // Formatta i dati per il frontend
            $result = $plannedExams->map(function ($plannedExam) {
                return [
                    'public_id' => $plannedExam->public_id,
                    'title' => $plannedExam->exam?->name,
                    'date' => $plannedExam->date ? $this->cleanDateTime($plannedExam->date, 'date') : null,
                    'time' => $plannedExam->time ? $this->cleanDateTime($plannedExam->time, 'time') : null,
                    'end_time' => $plannedExam->end_time ? $this->cleanDateTime($plannedExam->end_time, 'time') : null,
                    'location' => $plannedExam->location,
                    'color' => $plannedExam->exam?->color,
                    'tag' => $plannedExam->exam?->type,
                    'description' => $plannedExam->exam?->description,
                    'exam' => [
                        'public_id' => $plannedExam->exam?->public_id,
                        'name' => $plannedExam->exam?->name,
                        'type' => $plannedExam->exam?->type,
                        'color' => $plannedExam->exam?->color,
                        'cost' => $plannedExam->exam?->cost,
                    ],
                    'candidates_count' => $plannedExam->plannedExamCandidates->count(),
                    'test_center' => $plannedExam->testCenter ? [
                        'id' => $plannedExam->testCenter->id,
                        'name' => $plannedExam->testCenter->name ?? 'N/A',
                        'address' => $plannedExam->testCenter->address ?? null,
                    ] : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $result
            ], 200);
        } catch (\Exception $e) {
            Log::error('[PlannedExamController@myExams] Errore durante il recupero degli esami', [
                'user_id' => $user?->id,
                'role' => $userRole ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Errore durante il recupero degli esami: ' . $e->getMessage()
            ], 500);
        }
    }
}
