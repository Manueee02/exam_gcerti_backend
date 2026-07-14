<?php

namespace App\Http\Controllers;

use App\Models\AuditorCache;
use App\Models\Exam;
use App\Models\PlannedExam;
use App\Models\UserCreatedExaminerDecisionmaker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PlannedExamController extends Controller
{
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

    /**
     * Risolve l'AuditorCache associato all'utente loggato tramite
     * user_created_examiner_decisionmaker. Restituisce null se non trovato.
     */
    private function resolveAuditorForUser(int $userId): ?AuditorCache
    {
        $link = UserCreatedExaminerDecisionmaker::where('id_user', $userId)->first();

        if (!$link) {
            return null;
        }

        return AuditorCache::where('public_id', $link->auditor_public_id)->first();
    }

    // =========================================================
    // GET /api/planned-exams
    // =========================================================
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

        $examinerIds      = $plannedExams->pluck('id_examiner')->filter()->unique();
        $decisionMakerIds = $plannedExams->pluck('id_decision_maker')->filter()->unique();

        $examiners      = AuditorCache::whereIn('id', $examinerIds)->get()->keyBy('id');
        $decisionMakers = AuditorCache::whereIn('id', $decisionMakerIds)->get()->keyBy('id');

        $result = $plannedExams->map(function ($plannedExam) use ($examiners, $decisionMakers, $userCandidateId) {
            $exam          = $plannedExam->exam;
            $examiner      = $examiners->get($plannedExam->id_examiner);
            $decisionMaker = $decisionMakers->get($plannedExam->id_decision_maker);

            return [
                'public_id'      => $plannedExam->public_id,
                'title'          => $exam?->name,
                'date'           => $this->cleanDateTime($plannedExam->date, 'date'),
                'time'           => $this->cleanDateTime($plannedExam->time, 'time'),
                'end_time'       => $this->cleanDateTime($plannedExam->end_time, 'time'),
                'color'          => $exam?->color,
                'cost'           => $exam?->cost,
                'tag'            => $exam?->type,
                'location'       => $plannedExam->location,
                'description'    => $exam?->description,
                'organizer'      => $examiner
                    ? ['name' => $examiner->name . ' ' . $examiner->surname, 'public_id' => $examiner->public_id]
                    : null,
                'exam'           => ['public_id' => $exam?->public_id ?? null],
                'examiner'       => $examiner      ? ['public_id' => $examiner->public_id]      : null,
                'decision_maker' => $decisionMaker ? ['public_id' => $decisionMaker->public_id] : null,
                'already_enrolled' => $userCandidateId !== null
                    ? $plannedExam->inscriptions->isNotEmpty()
                    : null,
            ];
        });

        return response()->json($result);
    }

    // =========================================================
    // GET /api/planned-exams/{publicId}
    // =========================================================
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

        $user = Auth::user();
        if ($user) {
            $user->load('role');
        }
        $userRole = $user?->role?->name ?? null;
        $isAdmin  = $user && in_array($userRole, ['superAdmin', 'admin']);

        // =========================
        // VERIFICA AUTORIZZAZIONE EXAMINER/DM
        // Nuova logica: UserCreatedExaminerDecisionmaker → AuditorCache
        // (eliminati i riferimenti a Examiner::where e DecisionsMaker::where)
        // =========================
        $isExaminerOrDM          = false;
        $isAuthorizedForThisExam = false;

        if ($user && in_array($userRole, ['examiner', 'decisionMaker'])) {
            $auditor = $this->resolveAuditorForUser($user->id);

            if ($auditor) {
                $isExaminerOrDM = true;

                if ($userRole === 'examiner') {
                    $isAuthorizedForThisExam = ($plannedExam->id_examiner == $auditor->id);
                } else {
                    $isAuthorizedForThisExam = ($plannedExam->id_decision_maker == $auditor->id);
                }

            } else {
                Log::warning('[PlannedExamController@show] Nessun auditor associato all\'utente', [
                    'user_id'   => $user->id,
                    'user_role' => $userRole,
                ]);
            }
        }

        // =========================
        // ESAMINATORE / DECISION MAKER (lookup locale su AuditorCache)
        // =========================
        $examinerModel = $plannedExam->id_examiner
            ? AuditorCache::find($plannedExam->id_examiner)
            : null;

        $decisionMakerModel = $plannedExam->id_decision_maker
            ? AuditorCache::find($plannedExam->id_decision_maker)
            : null;

        $mapPerson = function (?AuditorCache $a) {
            if (!$a) return null;
            return [
                'public_id' => $a->public_id,
                'name'      => $a->name,
                'surname'   => $a->surname,
                'full_name' => trim($a->name . ' ' . $a->surname),
            ];
        };

        $data                   = $plannedExam->toArray();
        $data['examiner']       = $mapPerson($examinerModel);
        $data['decision_maker'] = $mapPerson($decisionMakerModel);

        // =========================
        // LOGICA USER vs ADMIN vs EXAMINER/DM
        // =========================
        if ($isAdmin || ($isExaminerOrDM && $isAuthorizedForThisExam)) {
            $plannedExam->load('plannedExamCandidates.candidate');

            $data['approved_candidates'] = $plannedExam->plannedExamCandidates->map(function ($pec) {
                $candidate = $pec->candidate;
                return [
                    'id'          => $candidate->id,
                    'public_id'   => $candidate->public_id ?? null,
                    'name'        => $candidate->name,
                    'surname'     => $candidate->surname,
                    'email'       => $candidate->email,
                    'phone'       => $candidate->phone,
                    'fiscal_code' => $candidate->fiscal_code,
                    'enrolled_at' => $pec->created_at?->format('Y-m-d H:i:s'),
                ];
            });

            if (!$isAdmin) {
                unset($data['inscriptions']);
            }
        } else {
            unset($data['candidate_exams']);
            unset($data['inscriptions']);
            unset($data['planned_exam_candidates']);

            $alreadyEnrolled            = false;
            $alreadyEnrolledSameExamType = false;

            if ($user?->candidate) {
                try {
                    $candidateId = $user->candidate->id;

                    $alreadyEnrolled = $plannedExam->inscriptions()
                        ->where('id_candidate', $candidateId)
                        ->whereNotIn('status', ['revoked', 'retired'])
                        ->exists();

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

            $data['already_enrolled']              = $alreadyEnrolled;
            $data['already_enrolled_same_exam_type'] = $alreadyEnrolledSameExamType;

            if ($isExaminerOrDM && !$isAuthorizedForThisExam) {
                Log::warning('[PlannedExamController@show] Examiner/DM non autorizzato per questo esame', [
                    'public_id' => $publicId,
                    'user_id'   => $user?->id,
                    'user_role' => $userRole,
                ]);
            }
        }

        return response()->json($data);
    }

    // =========================================================
    // POST /api/planned-exams
    // =========================================================
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

            $inscriptionGdprExists = \App\Models\GDPR::where('type', 'inscription')
                ->whereNull('id_exam')
                ->whereHas('activeVersion')
                ->exists();

            if (!$inscriptionGdprExists) {
                return response()->json([
                    'message' => 'Impossibile creare la sessione: nessun GDPR attivo di tipo "inscription" configurato. Crea prima il GDPR necessario.',
                ], 422);
            }

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

        $examinerCache      = AuditorCache::where('public_id', $validated['id_examiner'])->first();
        $decisionMakerCache = AuditorCache::where('public_id', $validated['id_decision_maker'])->first();

        if (!$examinerCache || !$decisionMakerCache) {
            Log::warning('[PlannedExamController@store] Esaminatore o decision maker non trovato nella cache', [
                'id_examiner'       => $validated['id_examiner'],
                'id_decision_maker' => $validated['id_decision_maker'],
            ]);
            return response()->json(['message' => 'Esaminatore o decision maker non trovato'], 404);
        }

        try {
            $plannedExam = PlannedExam::create([
                'id_exam'           => $exam->id,
                'id_examiner'       => $examinerCache->id,
                'id_decision_maker' => $decisionMakerCache->id,
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

    // =========================================================
    // PUT /api/planned-exams/{publicId}
    // =========================================================
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
            $cached = AuditorCache::where('public_id', $validated['id_examiner'])->first();
            if (!$cached) {
                return response()->json(['message' => 'Esaminatore non trovato'], 404);
            }
            $toUpdate['id_examiner'] = $cached->id;
        }

        if (isset($validated['id_decision_maker'])) {
            $cached = AuditorCache::where('public_id', $validated['id_decision_maker'])->first();
            if (!$cached) {
                return response()->json(['message' => 'Decision maker non trovato'], 404);
            }
            $toUpdate['id_decision_maker'] = $cached->id;
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

    // =========================================================
    // DELETE /api/planned-exams/{publicId}
    // =========================================================
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

    // =========================================================
    // GET /api/planned-exams/reference-data
    // =========================================================
    public function referenceData()
    {
        $requestId = uniqid('refdata_', true);

        Log::info('[PlannedExamController@referenceData] START', [
            'request_id' => $requestId,
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
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Errore nel recupero degli esami'], 500);
        }

        try {
            $examiners = AuditorCache::where('is_active', true)
                ->where('is_examiner', 'true')
                ->where('has_qualified_status', true)
                ->get(['public_id', 'name', 'surname'])
                ->values();
        } catch (\Exception $e) {
            Log::error('[PlannedExamController@referenceData] Errore nel recupero degli esaminatori', [
                'request_id' => $requestId,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Errore nel recupero degli esaminatori'], 500);
        }

        try {
            $decisionMakers = AuditorCache::where('is_active', true)
                ->where('is_decision_maker', true)
                ->where('has_qualified_status', true)
                ->get(['public_id', 'name', 'surname'])
                ->values();
        } catch (\Exception $e) {
            Log::error('[PlannedExamController@referenceData] Errore nel recupero dei decision makers', [
                'request_id' => $requestId,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Errore nel recupero dei decision makers'], 500);
        }

        Log::info('[PlannedExamController@referenceData] END - successo', [
            'request_id'            => $requestId,
            'exams_count'           => $exams->count(),
            'examiners_count'       => $examiners->count(),
            'decision_makers_count' => $decisionMakers->count(),
        ]);

        return response()->json([
            'exams'           => $exams,
            'examiners'       => $examiners,
            'decision_makers' => $decisionMakers,
        ]);
    }

    // =========================================================
    // GET /api/my-exams
    // =========================================================
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

        $user->loadMissing('role');
        $userRole = $user->role?->name;

        if (!in_array($userRole, ['examiner', 'decisionMaker'])) {
            Log::warning('[PlannedExamController@myExams] Ruolo non autorizzato', [
                'user_id' => $user->id,
                'role'    => $userRole,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Ruolo non autorizzato per questa operazione'
            ], 403);
        }

        try {
            $auditor = $this->resolveAuditorForUser($user->id);

            if (!$auditor) {
                Log::warning('[PlannedExamController@myExams] Nessun auditor associato all\'utente', [
                    'user_id' => $user->id,
                    'role'    => $userRole,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Nessun profilo auditor associato a questo utente'
                ], 404);
            }

            $query = PlannedExam::with([
                'exam',
                'testCenter',
                'plannedExamCandidates.candidate',
            ]);

            if ($userRole === 'examiner') {
                $query->where('id_examiner', $auditor->id);
            } else {
                $query->where('id_decision_maker', $auditor->id);
            }

            $plannedExams = $query->orderBy('date', 'desc')->get();

            Log::info('[PlannedExamController@myExams] Esami recuperati con successo', [
                'user_id'           => $user->id,
                'role'              => $userRole,
                'auditor_id'        => $auditor->id,
                'auditor_public_id' => $auditor->public_id,
                'count'             => $plannedExams->count(),
            ]);

            $result = $plannedExams->map(function ($plannedExam) {
                return [
                    'public_id'        => $plannedExam->public_id,
                    'title'            => $plannedExam->exam?->name,
                    'date'             => $plannedExam->date     ? $this->cleanDateTime($plannedExam->date, 'date')     : null,
                    'time'             => $plannedExam->time     ? $this->cleanDateTime($plannedExam->time, 'time')     : null,
                    'end_time'         => $plannedExam->end_time ? $this->cleanDateTime($plannedExam->end_time, 'time') : null,
                    'location'         => $plannedExam->location,
                    'color'            => $plannedExam->exam?->color,
                    'tag'              => $plannedExam->exam?->type,
                    'description'      => $plannedExam->exam?->description,
                    'exam'             => [
                        'public_id' => $plannedExam->exam?->public_id,
                        'name'      => $plannedExam->exam?->name,
                        'type'      => $plannedExam->exam?->type,
                        'color'     => $plannedExam->exam?->color,
                        'cost'      => $plannedExam->exam?->cost,
                    ],
                    'candidates_count' => $plannedExam->plannedExamCandidates->count(),
                    'test_center'      => $plannedExam->testCenter ? [
                        'id'      => $plannedExam->testCenter->id,
                        'name'    => $plannedExam->testCenter->name    ?? 'N/A',
                        'address' => $plannedExam->testCenter->address ?? null,
                    ] : null,
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $result
            ], 200);

        } catch (\Exception $e) {
            Log::error('[PlannedExamController@myExams] Errore durante il recupero degli esami', [
                'user_id' => $user->id,
                'role'    => $userRole,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Errore durante il recupero degli esami: ' . $e->getMessage()
            ], 500);
        }
    }
}
