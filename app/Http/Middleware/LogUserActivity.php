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
                'event_type' => 'api_call',
                'event_data' => json_encode([
                    'method' => $request->method(),
                    'url'    => $request->fullUrl(),
                    'query'   => $request->query(),
                    'payload' => $request->all(),
                    'params' => $request->except(['password', 'password_confirmation']),
                ]),
                'status' => $response->getStatusCode(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        }
        else {
            DB::table('user_logs')->insert([
                'event_type' => 'api_call',
                'event_data' => json_encode([
                    'method' => $request->method(),
                    'url'    => $request->fullUrl(),
                    'query'   => $request->query(),
                    'payload' => $request->all(),
                    'params' => $request->except(['password', 'password_confirmation']),
                ]),
                'status' => $response->getStatusCode(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        }

        return $response;
    }
}
