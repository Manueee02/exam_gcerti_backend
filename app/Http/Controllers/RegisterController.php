<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\VerifyEmail;
use App\Models\User;
use App\Models\EmailVerificationToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;

class RegisterController extends Controller
{
    public function register(Request $request)
    {
        // 1️⃣ Validazione dati
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        // 2️⃣ Creazione utente
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'id_role' => 3,
            'first_access' => 'false',
            'candidate_registration_completed' => 'false',
        ]);

        // 3️⃣ Creazione token verifica email
        $token = Str::random(60);
        EmailVerificationToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addHours(2),
            'sent_at' => now(),
        ]);

        // 4️⃣ Invio mail
        Mail::to($user->email)->send(new VerifyEmail($user, $token));

        return response()->json([
            'message' => 'Utente creato. Controlla la tua email per il link di verifica.',
        ], 201);
    }
}
