<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Authenticate extends Middleware
{
    protected function redirectTo(Request $request): ?string
    {
        Log::info('redirectTo called', [
            'url' => $request->fullUrl(),
            'is_api' => $request->is('api/*'),
            'expects_json' => $request->expectsJson(),
            'accept_header' => $request->header('Accept')
        ]);

        // SEMPRE restituire null per le route API
        if ($request->is('api/*')) {
            return null;
        }

        return null;
    }

    protected function unauthenticated($request, array $guards)
    {
        // Override del metodo per gestire meglio le API
        if ($request->is('api/*')) {
            Log::info('API unauthenticated response');
            throw new \Illuminate\Auth\AuthenticationException(
                'Unauthenticated.', $guards, null
            );
        }

        return parent::unauthenticated($request, $guards);
    }
}
