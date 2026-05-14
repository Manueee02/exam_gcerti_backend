<?php

namespace App\Http\Controllers;

use App\Models\DecisionsMaker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class DecisionMakerController extends Controller
{
    /**
     * Show all decision makers
     */
    public function index()
    {
        $decisionMakers = DecisionsMaker::with(['media'])
            ->where('active', 'true')
            ->get();

        return response()->json($decisionMakers, Response::HTTP_OK);
    }

    /**
     * Show decision maker by id
     */
    public function show($id)
    {
        $decisionMaker = DecisionsMaker::with(['user', 'media.media'])->find($id);

        if (!$decisionMaker) {
            return response()->json([
                'message' => 'Decision maker not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $decisionMaker->media = $decisionMaker->media->map(function ($mediaItem) {
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

        return response()->json($decisionMaker, Response::HTTP_OK);
    }

    /**
     * Create decision maker
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'surname' => 'required|string|max:255',
            'email'   => 'required|email|unique:decisions_makers,email',
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

        $decisionMaker = null;

        DB::transaction(function () use ($validated, &$decisionMaker) {

            $decisionMaker = DecisionsMaker::create([
                'name'    => $validated['name'],
                'surname' => $validated['surname'],
                'email'   => $validated['email'],
                'phone'   => $validated['phone'],
                'active'  => 'true',
            ]);

            foreach ($validated['media'] as $media) {
                \App\Models\DecisionsMakerMedia::create([
                    'id_decision_maker' => $decisionMaker->id,
                    'id_media'          => $media['id'],
                    'type'              => $media['type'],
                ]);
            }
        });

        return response()->json($decisionMaker->load('media'), 201);
    }

    /**
     * Update decision maker
     */
    public function update(Request $request, $id)
    {
        $decisionMaker = DecisionsMaker::find($id);

        if (!$decisionMaker) {
            return response()->json([
                'message' => 'Decision maker not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'name'    => 'sometimes|string|max:255',
            'surname' => 'sometimes|string|max:255',
            'email'   => 'sometimes|email|unique:decisions_makers,email,' . $decisionMaker->id,
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

        DB::transaction(function () use ($validated, $decisionMaker) {

            $decisionMaker->update(
                collect($validated)->except('media')->toArray()
            );

            if (isset($validated['media'])) {
                $decisionMaker->media()->delete();

                foreach ($validated['media'] as $media) {
                    \App\Models\DecisionsMakerMedia::create([
                        'id_decision_maker' => $decisionMaker->id,
                        'id_media'          => $media['id'],
                        'type'              => $media['type'],
                    ]);
                }
            }
        });

        return response()->json($decisionMaker->load('media'), Response::HTTP_OK);
    }

    /**
     * Soft delete (disable)
     */
    public function destroy($id)
    {
        $decisionMaker = DecisionsMaker::find($id);

        if (!$decisionMaker) {
            return response()->json([
                'message' => 'Decision maker not found'
            ], Response::HTTP_NOT_FOUND);
        }

        $decisionMaker->update([
            'active' => false
        ]);

        return response()->json([
            'message' => 'Decision maker disabled successfully'
        ], Response::HTTP_OK);
    }

    /**
     * Create user account for decision maker
     */
    public function createUser(Request $request, $id)
    {
        // Trova il decision maker
        $decisionMaker = DecisionsMaker::where('public_id', $id)->first();

        if (!$decisionMaker) {
            try {
                // Chiama il server esterno per ottenere i dati
                $response = Http::withToken(config('services.app1.token'))
                    ->get(config('services.app1.url') . '/examiner/' . $id);

                if (!$response->successful()) {
                    Log::warning('[DecisionMakerController] Decision maker non trovato né localmente né sul server esterno.', [
                        'decision_maker_public_id' => $id,
                        'admin_id' => Auth::id(),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Decision Maker non trovato'
                    ], Response::HTTP_NOT_FOUND);
                }

                // Estrai i dati dall'auditor del server esterno
                $externalData = $response->json('data.auditor');

                if (!$externalData) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Dati decision maker non validi dal server esterno'
                    ], Response::HTTP_INTERNAL_SERVER_ERROR);
                }

                // Crea una copia locale del decision maker
                $decisionMaker = DecisionsMaker::create([
                    'public_id' => $id,
                    'name'      => $externalData['name'],
                    'surname'   => $externalData['surname'],
                    'email'     => $externalData['email'],
                    'phone'     => $externalData['phone'] ?? null,
                    'active'    => 'true',
                ]);

                Log::info('[DecisionMakerController] Copia locale decision maker creata da server esterno.', [
                    'decision_maker_id' => $decisionMaker->id,
                    'public_id' => $id,
                ]);
            } catch (\Exception $e) {
                Log::error('[DecisionMakerController] Errore chiamata server esterno.', [
                    'public_id' => $id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Errore nel recupero dati decision maker: ' . $e->getMessage()
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        // Verifica se il decision maker ha già un utente associato
        if ($decisionMaker->id_user !== null) {
            Log::warning('[DecisionMakerController] Tentativo di creare utenza duplicata per decision maker.', [
                'decision_maker_id' => $decisionMaker->id,
                'existing_user_id' => $decisionMaker->id_user,
                'admin_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Il Decision Maker ha già un utente associato'
            ], Response::HTTP_CONFLICT);
        }

        // Valida i dati in input
        $request->validate([
            'email' => 'sometimes|email',
        ]);

        // Usa l'email fornita o quella del decision maker
        $email = $request->input('email', $decisionMaker->email);

        // Verifica se l'email esiste già nella tabella users
        if (\App\Models\User::where('email', $email)->exists()) {
            Log::warning('[DecisionMakerController] Tentativo di creare utenza con email già esistente.', [
                'decision_maker_id' => $decisionMaker->id,
                'email' => $email,
                'admin_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Un utente con questa email esiste già'
            ], Response::HTTP_CONFLICT);
        }

        // Trova il ruolo 'decisionMaker'
        $decisionMakerRole = \App\Models\UserRole::where('name', 'decisionMaker')->first();

        if (!$decisionMakerRole) {
            Log::error('[DecisionMakerController] Ruolo Decision Maker non trovato nel sistema.', [
                'decision_maker_id' => $decisionMaker->id,
                'admin_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ruolo Decision Maker non trovato nel sistema'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Genera password casuale seguendo il pattern esistente
        $rawPassword = strtolower(str_replace(' ', '.', $decisionMaker->name)) . '.' . strtolower(str_replace(' ', '.', $decisionMaker->surname)) . '.' . rand(1000, 9999);

        try {
            DB::beginTransaction();

            // Crea l'utente
            $newUser = \App\Models\User::create([
                'name'                             => $decisionMaker->name . ' ' . $decisionMaker->surname,
                'email'                            => $email,
                'password'                         => bcrypt($rawPassword),
                'id_role'                          => $decisionMakerRole->id,
                'email_verified_at'                => now(),
                'candidate_registration_completed' => 'false',
            ]);

            // Associa l'utente al decision maker
            $decisionMaker->id_user = $newUser->id;
            $decisionMaker->save();

            // Invia email con credenziali
            Mail::to($newUser->email)->send(new \App\Mail\UserCredentials($newUser, $rawPassword));

            DB::commit();

            Log::info('[DecisionMakerController] Utenza decision maker creata con successo.', [
                'decision_maker_id' => $decisionMaker->id,
                'user_id' => $newUser->id,
                'user_email' => $newUser->email,
                'admin_id' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Utente creato e credenziali inviate via email',
                'user'    => $newUser->load('role'),
                'decisionMaker' => $decisionMaker->fresh()->load('user')
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('[DecisionMakerController] Errore durante la creazione dell\'utenza decision maker.', [
                'decision_maker_id' => $decisionMaker->id,
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
