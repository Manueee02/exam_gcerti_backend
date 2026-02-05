<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EmailVerificationToken;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function verify($token)
    {
        // Trova il token
        $verification = EmailVerificationToken::where('token', $token)->first();

        if (!$verification) {
            return response()->json(['message' => 'Token non valido'], 400);
        }

        // Controllo se già usato
        if ($verification->used_at) {
            return response()->json(['message' => 'Token già usato'], 400);
        }

        // Controllo scadenza
        if ($verification->expires_at->isPast()) {
            return response()->json(['message' => 'Token scaduto'], 400);
        }

        // Aggiorna utente
        $user = $verification->user;
        $user->email_verified_at = now();
        $user->first_access = false;
        $user->candidate_registration_completed = false; // rimane false fino al completamento
        $user->save();

        // Segna token come usato
        $verification->used_at = now();
        $verification->save();


        return redirect(config('app.frontend_url') . '/email-verified');

    }
}
