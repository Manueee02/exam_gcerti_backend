<?php

namespace App\Http\Controllers;

use App\Models\Examiner;
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
    public function createUser(Request $request, $id)
    {
        // Cerca l'esaminatore localmente
        $examiner = Examiner::where('public_id', $id)->first();

        // Se non esiste localmente, prova a prenderlo dal server esterno
        if (!$examiner) {
            try {
                // Chiama il server esterno per ottenere i dati
                $response = Http::withToken(config('services.app1.token'))
                    ->get(config('services.app1.url') . '/examiner/' . $id);

                if (!$response->successful()) {
                    Log::warning('[ExaminerController] Esaminatore non trovato né localmente né sul server esterno.', [
                        'examiner_public_id' => $id,
                        'admin_id' => Auth::id(),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Esaminatore non trovato'
                    ], Response::HTTP_NOT_FOUND);
                }

                // Estrai i dati dall'auditor del server esterno
                $externalData = $response->json('data.auditor');

                if (!$externalData) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Dati esaminatore non validi dal server esterno'
                    ], Response::HTTP_INTERNAL_SERVER_ERROR);
                }

                // Crea una copia locale dell'esaminatore
                $examiner = Examiner::create([
                    'public_id' => $id,
                    'name'      => $externalData['name'],
                    'surname'   => $externalData['surname'],
                    'email'     => $externalData['email'],
                    'phone'     => $externalData['phone'] ?? null,
                    'active'    => 'true',
                ]);

                Log::info('[ExaminerController] Copia locale esaminatore creata da server esterno.', [
                    'examiner_id' => $examiner->id,
                    'public_id' => $id,
                ]);
            } catch (\Exception $e) {
                Log::error('[ExaminerController] Errore chiamata server esterno.', [
                    'public_id' => $id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Errore nel recupero dati esaminatore: ' . $e->getMessage()
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        // Verifica se l'esaminatore ha già un utente associato
        if ($examiner->id_user !== null) {
            Log::warning('[ExaminerController] Tentativo di creare utenza duplicata per esaminatore.', [
                'examiner_id' => $examiner->id,
                'existing_user_id' => $examiner->id_user,
                'admin_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'L\'esaminatore ha già un utente associato'
            ], Response::HTTP_CONFLICT);
        }

        // Valida i dati in input
        $request->validate([
            'email' => 'sometimes|email',
        ]);

        // Usa l'email fornita o quella dell'esaminatore
        $email = $request->input('email', $examiner->email);

        // Verifica se l'email esiste già nella tabella users
        if (\App\Models\User::where('email', $email)->exists()) {
            Log::warning('[ExaminerController] Tentativo di creare utenza con email già esistente.', [
                'examiner_id' => $examiner->id,
                'email' => $email,
                'admin_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Un utente con questa email esiste già'
            ], Response::HTTP_CONFLICT);
        }

        // Trova il ruolo 'examiner'
        $examinerRole = \App\Models\UserRole::where('name', 'examiner')->first();

        if (!$examinerRole) {
            Log::error('[ExaminerController] Ruolo esaminatore non trovato nel sistema.', [
                'examiner_id' => $examiner->id,
                'admin_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ruolo esaminatore non trovato nel sistema'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Genera password casuale seguendo il pattern esistente
        $rawPassword = strtolower(str_replace(' ', '.', $examiner->name)) . '.' . strtolower(str_replace(' ', '.', $examiner->surname)) . '.' . rand(1000, 9999);

        try {
            DB::beginTransaction();

            // Crea l'utente
            $newUser = \App\Models\User::create([
                'name'                             => $examiner->name . ' ' . $examiner->surname,
                'email'                            => $email,
                'password'                         => bcrypt($rawPassword),
                'id_role'                          => $examinerRole->id,
                'email_verified_at'                => now(),
                'candidate_registration_completed' => 'false',
            ]);

            // Associa l'utente all'esaminatore
            $examiner->id_user = $newUser->id;
            $examiner->save();

            // Invia email con credenziali
            Mail::to($newUser->email)->send(new \App\Mail\UserCredentials($newUser, $rawPassword));

            DB::commit();

            Log::info('[ExaminerController] Utenza esaminatore creata con successo.', [
                'examiner_id' => $examiner->id,
                'user_id' => $newUser->id,
                'user_email' => $newUser->email,
                'admin_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Utente creato e credenziali inviate via email',
                'user'    => $newUser->load('role'),
                'examiner' => $examiner->fresh()->load('user')
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('[ExaminerController] Errore durante la creazione dell\'utenza esaminatore.', [
                'examiner_id' => $examiner->id,
                'email' => $email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'admin_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Errore durante la creazione dell\'utente: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
