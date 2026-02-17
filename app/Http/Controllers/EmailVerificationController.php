<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EmailVerificationToken;
use Carbon\Carbon;
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
        if (now()->greaterThan($verification->expires_at)) {
            return response()->json(['message' => 'Token scaduto'], 400);
        }


        // Aggiorna utente
        $user = $verification->user;
        $user->email_verified_at = now();
        $user->first_access = "false";
        $user->candidate_registration_completed = "false"; // rimane false fino al completamento
        $user->save();

        // Segna token come usato
        $verification->used_at = now();
        $verification->save();


        return response()->json([
            'message' => 'Mail verificata correttamente',
        ], 201);

    }
}
