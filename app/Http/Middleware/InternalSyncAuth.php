<?php

// App\Http\Middleware\InternalSyncAuth.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class InternalSyncAuth
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken(); // legge "Authorization: Bearer ..."

        if (!$token || !hash_equals(config('services.internal_sync.token'), $token)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        //TODO when is online
        /*$allowedIps = explode(',', config('services.internal_sync.allowed_ips', ''));
        if (!empty(array_filter($allowedIps)) && !in_array($request->ip(), $allowedIps, true)) {
            \Illuminate\Support\Facades\Log::warning('[InternalSyncAuth] IP non autorizzato', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['message' => 'Forbidden'], 403);
        }*/

        /* Su APP1
         * 4. Worker della coda
Tu hai già Supervisor per i worker GCerti (6 prod + 6 staging). Aggiungi/verifica un worker (o una coda dedicata) che processi anche questo job. Se vuoi isolarlo dagli altri job pesanti (tipo ProcessGcertiChatJob), puoi metterlo su una coda separata:
phpSyncAuditorToExamJob::dispatch($auditor->id)->onQueue('sync')->afterCommit();
e configurare un worker Supervisor dedicato:
ini[program:laravel-sync-worker]
command=php /path/to/app1/artisan queue:work --queue=sync --tries=5 --timeout=30
numprocs=1
autostart=true
autorestart=true
Anche 1 solo worker basta, è un carico leggero.
5. Sync iniziale storico
Una volta deployato e con i worker attivi, lancia una volta sola:
bashphp artisan auditors:sync-all
così popoli auditors_cache su Exam con tutto lo storico esistente (examiner/DM già presenti).
6. (opzionale ma consigliato) Job di fallback schedulato
Per coprire eventuali fallimenti residui dopo i 5 retry del job (rete giù per più tempo, Exam down per manutenzione), aggiungi in routes/console.php o Kernel.php:
phpSchedule::command('auditors:sync-all')->dailyAt('03:00')->withoutOverlapping();
Una passata notturna di sicurezza, costa pochissimo e ti dà garanzia di convergenza anche se qualche evento si perde.
         */
        /*Su App2 (Exam)

         * */

        return $next($request);
    }
}

