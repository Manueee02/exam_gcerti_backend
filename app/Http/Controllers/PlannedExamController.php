<?php

namespace App\Http\Controllers;

use App\Models\AuditorCache;
use App\Models\Exam;
use App\Models\PlannedExam;
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

        // Lookup in blocco sulla cache locale (niente più chiamate HTTP)
        $examiners = AuditorCache::whereIn('id', $examinerIds)->get()->keyBy('id');
        $decisionMakers = AuditorCache::whereIn('id', $decisionMakerIds)->get()->keyBy('id');

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
                        'name'      => $examiner->name . ' ' . $examiner->surname,
                        'public_id' => $examiner->public_id,
                    ]
                    : null,
                'exam' => [
                    'public_id' => $exam?->public_id ?? null,
                ],
                'examiner' => $examiner
                    ? ['public_id' => $examiner->public_id]
                    : null,
                'decision_maker' => $decisionMaker
                    ? ['public_id' => $decisionMaker->public_id]
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
                $cached = AuditorCache::where('public_id', $examiner->public_id)->first();
                $isAuthorizedForThisExam = $cached && ($plannedExam->id_examiner == $cached->id);

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
                $cached = AuditorCache::where('public_id', $decisionMaker->public_id)->first();
                $isAuthorizedForThisExam = $cached && ($plannedExam->id_decision_maker == $cached->id);

                Log::info('[PlannedExamController@show] Decision maker verifica autorizzazione', [
                    'user_id' => $user->id,
                    'dm_public_id' => $decisionMaker->public_id,
                    'planned_exam_id_decision_maker' => $plannedExam->id_decision_maker,
                    'is_authorized' => $isAuthorizedForThisExam,
                ]);
            }
        }

        // =========================
        // ESAMINATORE / DECISION MAKER (lookup locale)
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

        $data = $plannedExam->toArray();
        $data['examiner'] = $mapPerson($examinerModel);
        $data['decision_maker'] = $mapPerson($decisionMakerModel);

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
            // USER normale O examiner/decisionMaker non autorizzato → rimuovi dati candidati
            unset($data['candidate_exams']);
            unset($data['inscriptions']);
            unset($data['planned_exam_candidates']);

            $alreadyEnrolled = false;
            $alreadyEnrolledSameExamType = false;

            if ($user?->candidate) {
                try {
                    $candidateId = $user->candidate->id;

                    // Iscritto a QUESTA sessione
                    $alreadyEnrolled = $plannedExam->inscriptions()
                        ->where('id_candidate', $candidateId)
                        ->whereNotIn('status', ['revoked', 'retired'])
                        ->exists();

                    // Iscritto a stessa TIPOLOGIA ESAME
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

        // Lookup locale invece di chiamata HTTP
        $examinerCache = AuditorCache::where('public_id', $validated['id_examiner'])->first();
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
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Errore nel recupero degli esami'], 500);
        }

        // =========================
        // EXAMINERS (lookup locale, niente più HTTP verso App1)
        // =========================
        try {
            $examiners = AuditorCache::active()
                ->where('is_examiner', 'true')
                ->where('has_qualified_status', true)
                ->get(['public_id', 'name', 'surname'])
                ->values();

            Log::info('[PlannedExamController@referenceData] Examiners OK', [
                'request_id' => $requestId,
                'count'      => $examiners->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('[PlannedExamController@referenceData] Errore nel recupero degli esaminatori', [
                'request_id' => $requestId,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);
            return response()->json(['message' => 'Errore nel recupero degli esaminatori'], 500);
        }

        // =========================
        // DECISION MAKERS (lookup locale)
        // =========================
        try {
            $decisionMakers = AuditorCache::active()
                ->where('is_decision_maker', true)
                ->where('has_qualified_status', true)
                ->get(['public_id', 'name', 'surname'])
                ->values();

            Log::info('[PlannedExamController@referenceData] DecisionMakers OK', [
                'request_id' => $requestId,
                'count'      => $decisionMakers->count(),
            ]);
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

                $cached = AuditorCache::where('public_id', $examiner->public_id)->first();

                if (!$cached) {
                    return response()->json(['success' => false, 'message' => 'Esaminatore non trovato nella cache'], 404);
                }

                $query->where('id_examiner', $cached->id);

                Log::info('[PlannedExamController@myExams] Recupero esami per esaminatore', [
                    'user_id' => $user->id,
                    'examiner_local_id' => $examiner->id,
                    'examiner_external_id' => $cached->id,
                ]);
            } elseif ($userRole === 'decisionMaker') {
                $decisionMaker = \App\Models\DecisionsMaker::where('id_user', $user->id)->first();

                if (!$decisionMaker) {
                    return response()->json(['success' => false, 'message' => 'Profilo decision maker non trovato'], 404);
                }

                $cached = AuditorCache::where('public_id', $decisionMaker->public_id)->first();

                if (!$cached) {
                    return response()->json(['success' => false, 'message' => 'Decision maker non trovato nella cache'], 404);
                }

                $query->where('id_decision_maker', $cached->id);

                Log::info('[PlannedExamController@myExams] Recupero esami per decision maker', [
                    'user_id' => $user->id,
                    'dm_local_id' => $decisionMaker->id,
                    'dm_external_id' => $cached->id,
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
