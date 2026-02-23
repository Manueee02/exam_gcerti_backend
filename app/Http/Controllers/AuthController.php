<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Models\User;

class AuthController extends Controller
{
    /**
     * Login
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $credentials = $request->only('email', 'password');

        // Tentativo di login
        if (!$token = Auth::attempt($credentials)) {
            return response()->json([
                'error' => 'Email o password non valide',
                'message' => 'Credenziali non valide'
            ], 401);
        }

        $user = Auth::user();

        // Controllo email verificata
        if (is_null($user->email_verified_at)) {
            Auth::logout();

            return response()->json([
                'error' => 'Email non verificata',
                'message' => 'Devi verificare la tua email per accedere'
            ], 403);
        }

        // 🔥 Invalida eventuale token precedente
        if ($user->active_token) {
            try {
                Auth::setToken($user->active_token)->invalidate(true);
            } catch (\Exception $e) {
                // Token già scaduto o non valido
            }
        }

        // Salva nuovo token attivo
        $user->active_token = $token;
        $user->save();

        $user->load('role');

        $refreshToken = $this->generateRefreshToken($user);

        return $this->respondWithToken($token, $refreshToken);
    }



    /**
     * Verifica il token corrente (utile per l'AuthGuard)
     */
    public function me()
    {
        $user = Auth::user()->load('role');

        if (!$user) {
            return response()->json([
                'error' => 'Token non valido',
                'message' => 'Utente non autenticato'
            ], 401);
        }

        return response()->json([
            'userData' => $this->formatUserData($user),
            'status' => 'success'
        ]);
    }

    /**
     * Refresh Token
     */
    public function refreshToken(Request $request)
    {
        $request->validate([
            'refreshToken' => ['required', 'string']
        ]);

        $refreshToken = $request->get('refreshToken');
        $user = User::where('refresh_token', $refreshToken)->first();

        if (!$user) {
            return response()->json([
                'error' => 'Refresh token non valido',
                'message' => 'Token di aggiornamento scaduto o non valido'
            ], 401);
        }

        // Effettua il login dell'utente e genera un nuovo access token
        Auth::login($user);

        try {
            $newToken = Auth::refresh();
        } catch (\Exception $e) {
            // Se il refresh fallisce, genera un nuovo token
            $newToken = Auth::login($user, true);
        }

        $user->active_token = $newToken;
        $user->save();

        // Ruota il refresh token per sicurezza
        $newRefreshToken = $this->generateRefreshToken($user);

        return $this->respondWithToken($newToken, $newRefreshToken);
    }

    /**
     * Logout
     */
    public function logout()
    {
        $user = Auth::user();

        if ($user) {
            $user->refresh_token = null;

            if ($user->active_token) {
                try {
                    Auth::setToken($user->active_token)->invalidate(true);
                } catch (\Exception $e) {}
            }

            $user->active_token = null;
            $user->save();
        }

        Auth::logout();

        return response()->json([
            'status' => 'success',
            'message' => 'Logout effettuato con successo',
        ]);
    }


    /**
     * Verifica se il token è ancora valido
     */
    public function verifyToken()
    {
        try {
            $user = Auth::user()->load('role');

            if (!$user) {
                return response()->json([
                    'valid' => false,
                    'message' => 'Token non valido'
                ], 401);
            }

            return response()->json([
                'valid' => true,
                'userData' => $this->formatUserData($user)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'valid' => false,
                'message' => 'Token scaduto o non valido'
            ], 401);
        }
    }

    /**
     * Genera e salva un nuovo refresh token
     */
    protected function generateRefreshToken($user)
    {
        $refreshToken = Str::random(60);
        $user->refresh_token = $refreshToken;
        $user->save();

        return $refreshToken;
    }

    /**
     * Formatta i dati utente per il frontend
     */
    protected function formatUserData($user)
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role ? $user->role->name : 'user',
            'email_verified_at' => $user->email_verified_at,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'first_access' => $user->first_access,
            'candidate_registration_completed' => $user->candidate_registration_completed,
        ];
    }

    /**
     * Restituisce la risposta con i token
     */
    protected function respondWithToken($token, $refreshToken)
    {
        $user = Auth::user()->load('role');

        return response()->json([
            'access_token' => $token,
            'refresh_token' => $refreshToken,
            'token_type' => 'bearer',
            'expires_in' => Auth::factory()->getTTL() * 60,
            'userData' => $this->formatUserData($user), // Cambiato da 'user' a 'userData'
            'status' => 'success',
            'message' => 'Login effettuato con successo'
        ]);
    }

    /**
     * Aggiorna i dati del profilo utente
     */
    public function updateProfile(Request $request)
    {
        $user = Auth::user()->load('role');

        if (!$user) {
            return response()->json([
                'error' => 'Utente non autenticato'
            ], 401);
        }

        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'email', 'max:255', 'unique:users,email,' . $user->id],
            // Aggiungi altre validazioni per i campi che vuoi permettere di aggiornare
        ]);

        $user->update($request->only([
            'name',
            'email',
            // altri campi aggiornabili
        ]));

        return response()->json([
            'status' => 'success',
            'message' => 'Profilo aggiornato con successo',
            'userData' => $this->formatUserData($user->fresh())
        ]);
    }

    /**
     * Cambia password
     */
    public function changePassword(Request $request)
    {
        $user = Auth::user()->load('role');

        if (!$user) {
            return response()->json([
                'error' => 'Utente non autenticato'
            ], 401);
        }

        $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'error' => 'Password attuale non corretta'
            ], 400);
        }

        $user->update([
            'password' => Hash::make($request->new_password)
        ]);

        // Invalida tutti i refresh token esistenti per sicurezza
        $user->refresh_token = null;
        $user->first_access = 'false';
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Password cambiata con successo'
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
        ], [
            'email.exists' => 'Non esiste un account con questo indirizzo email'
        ]);

        $user = User::where('email', $request->email)->first();

        // Genera token di reset
        $token = Str::random(60);

        // Elimina eventuali token precedenti per questo utente
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        // Salva il nuovo token
        DB::table('password_reset_tokens')->insert([
            'email' => $request->email,
            'token' => Hash::make($token),
            'created_at' => Carbon::now()
        ]);

        // Invia email
        try {
            Mail::to($user->email)->send(new \App\Mail\ResetPasswordMail($user, $token));

            return response()->json([
                'status' => 'success',
                'message' => 'Email di reset password inviata con successo'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Errore nell\'invio dell\'email',
                'message' => 'Si è verificato un errore durante l\'invio dell\'email'
            ], 500);
        }
    }

    /**
     * Verifica validità token di reset
     */
    public function verifyResetToken(Request $request)
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email']
        ]);

        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$resetRecord) {
            return response()->json([
                'valid' => false,
                'message' => 'Token non valido o scaduto'
            ], 400);
        }

        // Verifica se il token è scaduto (es. 1 ora)
        $tokenAge = Carbon::parse($resetRecord->created_at)->addHour();
        if (Carbon::now()->greaterThan($tokenAge)) {
            // Elimina token scaduto
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();

            return response()->json([
                'valid' => false,
                'message' => 'Token scaduto'
            ], 400);
        }

        // Verifica il token
        if (!Hash::check($request->token, $resetRecord->token)) {
            return response()->json([
                'valid' => false,
                'message' => 'Token non valido'
            ], 400);
        }

        return response()->json([
            'valid' => true,
            'message' => 'Token valido'
        ]);
    }

    /**
     * Reset password con token
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'token' => ['required', 'string']
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'error' => 'Utente non trovato',
                'message' => 'L\'utente specificato non esiste'
            ], 404);
        }

        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$resetRecord) {
            return response()->json([
                'error' => 'Token non valido',
                'message' => 'Token non valido o scaduto'
            ], 400);
        }

        // Verifica se il token è scaduto
        $tokenAge = Carbon::parse($resetRecord->created_at)->addHour();
        if (Carbon::now()->greaterThan($tokenAge)) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();

            return response()->json([
                'error' => 'Token scaduto',
                'message' => 'Il token è scaduto, richiedi un nuovo reset'
            ], 400);
        }

        // Verifica il token
        if (!Hash::check($request->token, $resetRecord->token)) {
            return response()->json([
                'error' => 'Token non valido',
                'message' => 'Il token fornito non è valido'
            ], 400);
        }

        // Aggiorna la password
        $user->update([
            'password' => Hash::make($request->password),
            'first_access' => 'false'
        ]);

        // Invalida tutti i refresh token esistenti
        $user->refresh_token = null;
        $user->save();

        // Elimina il token di reset utilizzato
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Password cambiata con successo'
        ]);
    }

}
