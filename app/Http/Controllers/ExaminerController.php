<?php

namespace App\Http\Controllers;

use App\Mail\UserCredentials;
use App\Models\AuditorCache;
use App\Models\Examiner;
use App\Models\User;
use App\Models\UserCreatedExaminerDecisionmaker;
use App\Models\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class ExaminerController extends Controller
{
    /**
     * Show all examiners
     */
    public function index()
    {
        $examiners = Examiner::with(['media'])
            ->where('active', 'true')
            ->get();

        return response()->json($examiners, Response::HTTP_OK);
    }

    /**
     * Show examiner by id
     */
    public function show($id)
    {
        $examiner = Examiner::with(['user', 'media.media'])->find($id);

        if (!$examiner) {
            return response()->json([
                'message' => 'Examiner not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $examiner->media = $examiner->media->map(function ($mediaItem) {
            return [
                'id' => $mediaItem->id,
                'type' => $mediaItem->type,
                'file_data' => [
                    'id' => $mediaItem->media->id,
                    'original_name' => $mediaItem->media->original_name,
                    'mime_type' => $mediaItem->media->mime_type,
                    'size' => $mediaItem->media->size,
                    'disk' => $mediaItem->media->disk,
                    'path' => $mediaItem->media->path,
                    'url' => $mediaItem->media->url,
                    'md5_hash' => $mediaItem->media->md5_hash,
                    'created_at' => $mediaItem->media->created_at,
                    'updated_at' => $mediaItem->media->updated_at,
                ],
                'created_at' => $mediaItem->created_at,
                'updated_at' => $mediaItem->updated_at,
            ];
        });

        return response()->json($examiner, Response::HTTP_OK);
    }



    /**
     * Create examiner
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:100',
            'surname' => 'required|string|max:100',
            'email'   => 'required|email|unique:examiners,email',
            'phone' => 'required|string|max:50',
            'media'               => 'required|array|min:1',
            'media.*.type'        => 'required|string|max:50',
            'media.*.id'          => 'required|integer|exists:media,id',
        ]);

        $hasIdentityDocument = collect($validated['media'])
            ->contains(fn($m) => $m['type'] === 'identity_document');

        if (!$hasIdentityDocument) {
            return response()->json([
                'message' => 'Il documento d\'identità è obbligatorio'
            ], 422);
        }

        $examiner = null;

        DB::transaction(function () use ($validated, &$examiner) {

            $examiner = \App\Models\Examiner::create([
                'name'    => $validated['name'],
                'surname' => $validated['surname'],
                'email'   => $validated['email'],
                'phone'   => $validated['phone'],
                'active'  => 'true',
            ]);

            foreach ($validated['media'] as $media) {
                \App\Models\ExaminerMedia::create([
                    'id_examiner' => $examiner->id,
                    'id_media'    => $media['id'],
                    'type'        => $media['type'],
                ]);
            }
        });

        return response()->json($examiner->load('media'), 201);
    }

    /**
     * Update examiner
     */
    public function update(Request $request, $id)
    {
        $examiner = \App\Models\Examiner::find($id);

        if (!$examiner) {
            return response()->json([
                'message' => 'Examiner not found'
            ], 404);
        }

        $validated = $request->validate([
            'name'    => 'sometimes|string|max:255',
            'surname' => 'sometimes|string|max:255',
            'email'   => 'sometimes|email|unique:examiners,email,' . $examiner->id,
            'phone'   => 'nullable|string|max:50',
            'media'        => 'sometimes|array|min:1',
            'media.*.type' => 'required_with:media|string|max:50',
            'media.*.id'   => 'required_with:media|integer|exists:media,id',
        ]);

        if (isset($validated['media'])) {
            $hasIdentityDocument = collect($validated['media'])
                ->contains(fn($media) => $media['type'] === 'identity_document');

            if (!$hasIdentityDocument) {
                return response()->json([
                    'message' => 'Identity document is required'
                ], 422);
            }
        }

        DB::transaction(function () use ($validated, $examiner) {

            $examiner->update(
                collect($validated)->except('media')->toArray()
            );

            if (isset($validated['media'])) {
                $examiner->media()->delete();

                foreach ($validated['media'] as $media) {
                    \App\Models\ExaminerMedia::create([
                        'id_examiner' => $examiner->id,
                        'id_media'    => $media['id'],
                        'type'        => $media['type'],
                    ]);
                }
            }
        });

        return response()->json($examiner->load('media'), 200);
    }


    /**
     * Soft delete (disattiva)
     */
    public function destroy($id)
    {
        $examiner = Examiner::find($id);

        if (!$examiner) {
            return response()->json([
                'message' => 'Examiner not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $examiner->update([
            'active' => 'false'
        ]);

        return response()->json([
            'message' => 'Examiner disabled successfully'
        ], Response::HTTP_OK);
    }

    /**
     * Create user account for examiner
     */
    public function createUser(Request $request, string $auditorPublicId): JsonResponse
    {
        // 1. Valida input — role è obbligatorio e deve essere uno dei due valori ammessi
        $request->validate([
            'email' => 'sometimes|nullable|email',
            'role'  => 'required|in:examiner,decisionMaker',
        ]);

        $requestedRole = $request->input('role');

        // 2. Verifica che l'auditor esista in cache e sia attivo
        $auditor = AuditorCache::where('public_id', $auditorPublicId)
            ->where('is_active', true)
            ->first();

        if (!$auditor) {
            Log::warning('[ExaminerController@createUser] Auditor non trovato in cache.', [
                'auditor_public_id' => $auditorPublicId,
                'admin_id'          => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Esaminatore/Decision Maker non trovato in cache. Verificare che la sincronizzazione con App1 sia aggiornata.',
            ], 404);
        }

        // 3. Verifica coerenza ruolo ↔ flag auditor in cache
        if ($requestedRole === 'examiner' && $auditor->is_examiner !== 'true') {
            return response()->json([
                'success' => false,
                'message' => "L'auditor {$auditor->name} {$auditor->surname} non è abilitato come esaminatore in App1.",
            ], 422);
        }

        if ($requestedRole === 'decisionMaker' && !$auditor->is_decision_maker) {
            return response()->json([
                'success' => false,
                'message' => "L'auditor {$auditor->name} {$auditor->surname} non è abilitato come decision maker in App1.",
            ], 422);
        }

        // 4. Verifica che non abbia già un utente associato
        $alreadyLinked = UserCreatedExaminerDecisionmaker::where(
            'auditor_public_id',
            $auditorPublicId
        )->exists();

        if ($alreadyLinked) {
            return response()->json([
                'success' => false,
                'message' => "L'auditor {$auditor->name} {$auditor->surname} ha già un utente associato.",
            ], 409);
        }

        // 5. Email: body oppure cache
        $email = $request->input('email') ?: $auditor->email;

        if (!$email) {
            return response()->json([
                'success' => false,
                'message' => 'Nessuna email disponibile: fornirla nel body oppure aggiornare la cache.',
            ], 422);
        }

        if (User::where('email', $email)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Un utente con questa email esiste già.',
            ], 409);
        }

        // 6. Ruolo Laravel corrispondente
        $userRole = UserRole::where('name', $requestedRole)->first();

        if (!$userRole) {
            Log::error('[ExaminerController@createUser] Ruolo non trovato nel sistema.', [
                'role'     => $requestedRole,
                'admin_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => "Ruolo '{$requestedRole}' non trovato nel sistema.",
            ], 500);
        }

        // 7. Creazione utente + link in transazione
        $rawPassword = strtolower(str_replace(' ', '.', $auditor->name))
            . '.'
            . strtolower(str_replace(' ', '.', $auditor->surname))
            . '.'
            . rand(1000, 9999);

        $newUser = DB::transaction(function () use ($auditor, $email, $rawPassword, $userRole) {
            $user = User::create([
                'name'                             => $auditor->name . ' ' . $auditor->surname,
                'email'                            => $email,
                'password'                         => bcrypt($rawPassword),
                'id_role'                          => $userRole->id,
                'email_verified_at'                => now(),
                'candidate_registration_completed' => 'false',
            ]);

            UserCreatedExaminerDecisionmaker::create([
                'auditor_public_id' => $auditor->public_id,
                'id_user'           => $user->id,
            ]);

            return $user;
        });

        Mail::to($newUser->email)->send(new UserCredentials($newUser, $rawPassword));

        Log::info('[ExaminerController@createUser] Utenza creata con successo.', [
            'auditor_id'        => $auditor->id,
            'auditor_public_id' => $auditor->public_id,
            'role'              => $requestedRole,
            'user_id'           => $newUser->id,
            'admin_id'          => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Utente creato e credenziali inviate via email',
            'user'    => $newUser->load('role'),
            'auditor' => [
                'id'               => $auditor->id,
                'public_id'        => $auditor->public_id,
                'name'             => $auditor->name,
                'surname'          => $auditor->surname,
                'is_examiner'      => $auditor->is_examiner,
                'is_decision_maker' => $auditor->is_decision_maker,
            ],
        ], 201);
    }}
