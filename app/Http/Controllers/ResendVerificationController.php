<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\EmailVerificationToken;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use App\Mail\VerifyEmail;

class ResendVerificationController extends Controller
{
    public function resend(Request $request)
    {
        // 1️⃣ Validazione email
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        // 2️⃣ Trova utente
        $user = User::where('email', $request->email)->first();

        if ($user->email_verified_at) {
            return response()->json(['message' => 'Email già verificata'], 400);
        }

        // 3️⃣ Controllo ultimo token
        $lastToken = EmailVerificationToken::where('user_id', $user->id)
            ->orderBy('sent_at', 'desc')
            ->first();

        if ($lastToken && Carbon::parse($lastToken->sent_at)->diffInMinutes(now()) < 2) {
            return response()->json(['message' => 'Attendere almeno 2 minuti prima di richiedere un nuovo link'], 429);
        }

        // 4️⃣ Genera nuovo token
        $token = Str::random(60);
        EmailVerificationToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addHours(2),
            'sent_at' => now(),
        ]);

        // 5️⃣ Invia email
        Mail::to($user->email)->send(new VerifyEmail($user, $token));

        return response()->json(['message' => 'Nuovo link di verifica inviato via email']);
    }
}
