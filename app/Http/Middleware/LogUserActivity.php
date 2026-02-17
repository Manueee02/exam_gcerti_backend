<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LogUserActivity
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request); // passa la richiesta al prossimo middleware/controller

        // Log solo se l'utente è autenticato
        if (auth()->check()) {
            DB::table('user_logs')->insert([
                'user_id'    => auth()->id(),
                'event_type' => 'api_call', // puoi variare: login, logout, ecc.
                'event_data' => json_encode([
                    'method' => $request->method(),
                    'url'    => $request->fullUrl(),
                    'params' => $request->except(['password', 'password_confirmation']),
                ]),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        }

        return $response;
    }
}
