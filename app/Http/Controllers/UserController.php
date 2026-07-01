<?php

namespace App\Http\Controllers;

use App\Models\AuditorCache;
use App\Models\User;
use App\Models\UserCreatedExaminerDecisionmaker;
use App\Models\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\UserCredentials;

class UserController extends Controller
{
    /**
     * Lista di tutti gli utenti con i ruoli associati
     */
    public function index()
    {
        $users = User::with('role')->get();
        return response()->json($users);
    }

    /**
     * Cambio ruolo di un utente
     */
    public function updateRole(Request $request, string $publicId)
    {
        $request->validate([
            'id_role' => 'required|exists:user_roles,id',
        ]);

        $user = User::where('id', $publicId)->firstOrFail();
        $user->id_role = $request->id_role;
        $user->save();

        return response()->json([
            'message' => 'Ruolo aggiornato con successo',
            'user'    => $user->load('role'),
        ]);
    }

    /**
     * Eliminazione utente
     */
    public function destroy(string $publicId)
    {
        $user = User::where('id', $publicId)->firstOrFail();
        $user->delete();

        return response()->json(['message' => 'Utente eliminato con successo']);
    }

    public function indexRoles(): JsonResponse
    {
        $roles = UserRole::all();
        return response()->json($roles);
    }

    /**
     * Crea un utente generico (non collegato ad alcun auditor)
     */
    public function store(Request $request)
    {
        $user = Auth::user()?->load('role');

        if (!$user || $user->role->name !== 'superAdmin') {
            return response()->json([
                'success' => false,
                'message' => 'Non sei autorizzato a creare utenti',
            ], 403);
        }

        $request->validate([
            'name'    => 'required|string|max:255',
            'email'   => 'required|email',
            'id_role' => 'required|exists:user_roles,id',
        ]);

        if (User::where('email', $request->email)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Un utente con questa email esiste già',
            ], 409);
        }

        $rawPassword = strtolower(str_replace(' ', '.', $request->name)) . '.' . rand(1000, 9999);

        $newUser = User::create([
            'name'                             => $request->name,
            'email'                            => $request->email,
            'password'                         => bcrypt($rawPassword),
            'id_role'                          => $request->id_role,
            'email_verified_at'                => now(),
            'candidate_registration_completed' => 'false',
        ]);

        Mail::to($newUser->email)->send(new UserCredentials($newUser, $rawPassword));

        return response()->json([
            'success' => true,
            'message' => 'Utente creato e credenziali inviate via email',
            'user'    => $newUser->load('role'),
        ], 201);
    }

    /**
     * Crea un utente collegato a un auditor di App1.
     *
     * Logica:
     *  - verifica che auditor_public_id esista in auditors_cache
     *  - verifica coerenza tra ruolo richiesto e flag dell'auditor in cache
     *    (examiner → is_examiner = 'true'; decisionMaker → is_decision_maker = true)
     *  - verifica che quell'auditor non abbia già un utente associato
     *  - crea user + link in user_created_examiner_decisionmaker in transazione
     */
    public function storeServerApp1User(Request $request): JsonResponse
    {
        $authUser = Auth::user()?->load('role');

        if (!$authUser || !in_array($authUser->role->name, ['admin', 'superAdmin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Non sei autorizzato a creare utenti',
            ], 403);
        }

        $request->validate([
            'name'              => 'required|string|max:255',
            'email'             => 'required|email',
            'id_role'           => 'required|exists:user_roles,id',
            'auditor_public_id' => 'required|string',
        ]);

        // 1. Verifica che l'auditor esista in cache
        $auditor = AuditorCache::where('public_id', $request->auditor_public_id)
            ->where('is_active', true)
            ->first();

        if (!$auditor) {
            return response()->json([
                'success' => false,
                'message' => 'Auditor non trovato in cache o non attivo. Verificare che la sincronizzazione con App1 sia aggiornata.',
            ], 404);
        }

        // 2. Verifica coerenza ruolo ↔ flag auditor
        $role = UserRole::find($request->id_role);
        $roleError = $this->validateRoleConsistency($role->name, $auditor);

        if ($roleError) {
            return response()->json([
                'success' => false,
                'message' => $roleError,
            ], 422);
        }

        // 3. Verifica che questo auditor non abbia già un utente
        $alreadyLinked = UserCreatedExaminerDecisionmaker::where(
            'auditor_public_id',
            $request->auditor_public_id
        )->exists();

        if ($alreadyLinked) {
            return response()->json([
                'success' => false,
                'message' => 'Questo auditor ha già un utente associato.',
            ], 409);
        }

        // 4. Verifica email non già in uso
        if (User::where('email', $request->email)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Un utente con questa email esiste già',
            ], 409);
        }

        // 5. Creazione user + link in transazione
        $rawPassword = strtolower(str_replace(' ', '.', $request->name)) . '.' . rand(1000, 9999);

        $newUser = DB::transaction(function () use ($request, $rawPassword, $auditor) {
            $user = User::create([
                'name'                             => $request->name,
                'email'                            => $request->email,
                'password'                         => bcrypt($rawPassword),
                'id_role'                          => $request->id_role,
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
    }

    /**
     * Verifica se un auditor ha già un utente associato.
     */
    public function auditorHasUser(string $auditorPublicId): JsonResponse
    {
        // Con auditors_cache possiamo anche arricchire la risposta
        // confermando che l'auditor esiste davvero, non solo il link
        $auditor = AuditorCache::where('public_id', $auditorPublicId)->first();

        if (!$auditor) {
            return response()->json([
                'has_user'      => false,
                'auditor_found' => false,
                'message'       => 'Auditor non trovato in cache',
            ], 404);
        }

        $exists = UserCreatedExaminerDecisionmaker::where(
            'auditor_public_id',
            $auditorPublicId
        )->exists();

        return response()->json([
            'has_user'      => $exists,
            'auditor_found' => true,
            'auditor'       => [
                'id'               => $auditor->id,
                'public_id'        => $auditor->public_id,
                'name'             => $auditor->name,
                'surname'          => $auditor->surname,
                'is_examiner'      => $auditor->is_examiner,
                'is_decision_maker' => $auditor->is_decision_maker,
                'is_active'        => $auditor->is_active,
            ],
        ], 200);
    }

    /**
     * Verifica che il ruolo assegnato sia compatibile con le flag
     * dell'auditor in cache. Restituisce un messaggio di errore
     * se c'è incoerenza, null se tutto è ok.
     */
    private function validateRoleConsistency(string $roleName, AuditorCache $auditor): ?string
    {
        if ($roleName === 'examiner' && $auditor->is_examiner !== 'true') {
            return "Impossibile assegnare il ruolo 'examiner': l'auditor {$auditor->name} {$auditor->surname} non ha il flag is_examiner attivo in App1.";
        }

        if ($roleName === 'decisionMaker' && !$auditor->is_decision_maker) {
            return "Impossibile assegnare il ruolo 'decisionMaker': l'auditor {$auditor->name} {$auditor->surname} non ha il flag is_decision_maker attivo in App1.";
        }

        // Se il ruolo non è né examiner né decisionMaker, non c'è
        // nessuna coerenza da verificare (es. admin che crea altri admin)
        return null;
    }
}
