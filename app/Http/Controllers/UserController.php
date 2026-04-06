<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserCreatedExaminerDecisionmaker;
use App\Models\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        $user = User::where('public_id', $publicId)->firstOrFail();
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
        $user = User::where('public_id', $publicId)->firstOrFail();
        $user->delete();

        return response()->json(['message' => 'Utente eliminato con successo']);
    }

    public function indexRoles(): JsonResponse
    {
        $roles = UserRole::all();

        return response()->json($roles);
    }

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

    public function storeServerApp1User(Request $request)
    {
        $user = Auth::user()?->load('role');

        if (!$user || $user->role->name !== 'superAdmin') {
            return response()->json([
                'success' => false,
                'message' => 'Non sei autorizzato a creare utenti',
            ], 403);
        }

        $request->validate([
            'name'             => 'required|string|max:255',
            'email'            => 'required|email',
            'id_role'          => 'required|exists:user_roles,id',
            'auditor_public_id' => 'required|string',
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

        UserCreatedExaminerDecisionmaker::create([
            'auditor_public_id' => $request->auditor_public_id,
            'id_user'           => $newUser->id,
        ]);

        Mail::to($newUser->email)->send(new UserCredentials($newUser, $rawPassword));

        return response()->json([
            'success' => true,
            'message' => 'Utente creato e credenziali inviate via email',
            'user'    => $newUser->load('role'),
        ], 201);
    }

    public function auditorHasUser(string $auditorPublicId)
    {
        $exists = UserCreatedExaminerDecisionmaker::where('auditor_public_id', $auditorPublicId)->exists();

        return response()->json([
            'has_user' => $exists,
        ], 200);
    }
}
