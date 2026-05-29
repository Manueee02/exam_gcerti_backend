<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\VerifyEmail;
use App\Models\GDPRVersion;
use App\Models\GDPRSigned;
use App\Models\User;
use App\Models\EmailVerificationToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;

class RegisterController extends Controller
{
    public function register(Request $request)
    {
        if (User::where('email', $request->email)->exists()) {
            return response()->json([
                'message' => 'Email già presente nel sistema.'
            ], 409);
        }
        // 1️⃣ Validazione dati
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'id_gdpr_version' => 'required|string|exists:GDPR_versions,public_id',
        ]);

        // Verifica che la versione GDPR sia attiva e di tipo 'cookie'
        $gdprVersion = GDPRVersion::where('public_id', $request->id_gdpr_version)
            ->where('active', true)
            ->whereHas('gdpr', fn($q) => $q->where('type', 'cookie'))
            ->first();

        if (!$gdprVersion) {
            return response()->json([
                'message' => 'Il consenso cookie non è valido o non è attivo.'
            ], 422);
        }

        // 2️⃣ Transazione: utente + token verifica + consenso GDPR atomici
        $result = DB::transaction(function () use ($request, $gdprVersion) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'id_role' => 3,
                'first_access' => 'false',
                'candidate_registration_completed' => 'false',
            ]);

            $token = Str::random(60);
            EmailVerificationToken::create([
                'user_id' => $user->id,
                'token' => $token,
                'expires_at' => now()->addHours(2),
                'sent_at' => now(),
            ]);

            // id_candidate è null: il candidato non esiste ancora al momento della registrazione
            GDPRSigned::create([
                'id_gdpr_version' => $gdprVersion->id,
                'id_candidate'    => null,
                'id_user'         => $user->id,
                'accepted_at'     => now(),
                'accepted'        => 'true',
                'date'            => now()->toDateString(),
            ]);

            return ['user' => $user, 'token' => $token];
        });

        // 4️⃣ Invio mail
        Mail::to($result['user']->email)->send(new VerifyEmail($result['user'], $result['token']));

        return response()->json([
            'message' => 'Utente creato. Controlla la tua email per il link di verifica.',
        ], 201);
    }
}
