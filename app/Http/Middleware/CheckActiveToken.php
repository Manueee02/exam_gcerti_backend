<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckActiveToken
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if ($user) {
            $currentToken = $request->bearerToken();

            if ($user->active_token !== $currentToken) {
                return response()->json([
                    'error' => 'Sessione non valida o accesso da un altro dispositivo'
                ], 401);
            }
        }

        return $next($request);
    }
}
