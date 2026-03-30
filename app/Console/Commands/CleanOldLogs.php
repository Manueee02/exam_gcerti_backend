<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CleanOldLogs extends Command
{
    protected $signature = 'logs:clean {--days=15 : Numero di giorni di retention}';
    protected $description = 'Elimina i log più vecchi di N giorni (DB + file)';

    public function handle(): int
    {
        $days      = (int) $this->option('days');
        $threshold = Carbon::now()->subDays($days);

        $this->info("🧹 Pulizia log più vecchi di {$days} giorni (prima del {$threshold->toDateString()})...");

        // --- 1. Tabella user_logs ---
        $deletedUserLogs = DB::table('user_logs')
            ->where('created_at', '<', $threshold)
            ->delete();

        $this->info("✅ user_logs eliminati: {$deletedUserLogs}");

        // --- 2. Tabella logs ---
        $deletedLogs = DB::table('logs')
            ->where('created_at', '<', $threshold)
            ->delete();

        $this->info("✅ logs eliminati: {$deletedLogs}");

        // --- 3. File laravel.log (righe più vecchie di N giorni) ---
        $logFile = storage_path('logs/laravel.log');
        $deletedLines = $this->cleanLogFile($logFile, $threshold);

        $this->info("✅ Righe log file eliminate: {$deletedLines}");

        Log::info("CleanOldLogs completato", [
            'days'              => $days,
            'threshold'         => $threshold->toDateTimeString(),
            'user_logs_deleted' => $deletedUserLogs,
            'logs_deleted'      => $deletedLogs,
            'log_file_lines'    => $deletedLines,
        ]);

        $this->info('🎉 Pulizia completata.');
        return Command::SUCCESS;
    }

    private function cleanLogFile(string $path, Carbon $threshold): int
    {
        if (! file_exists($path)) {
            $this->warn("File non trovato: {$path}");
            return 0;
        }

        $lines    = file($path, FILE_IGNORE_NEW_LINES);
        $kept     = [];
        $deleted  = 0;
        $skip     = false;

        foreach ($lines as $line) {
            // Le righe di apertura del log Laravel hanno formato: [YYYY-MM-DD HH:MM:SS]
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m)) {
                $lineDate = Carbon::createFromFormat('Y-m-d H:i:s', $m[1]);
                $skip     = $lineDate->lt($threshold);

                if ($skip) {
                    $deleted++;
                    continue;
                }
            }

            // Righe di continuazione (stack trace, ecc.) seguono il blocco corrente
            if ($skip) {
                $deleted++;
                continue;
            }

            $kept[] = $line;
        }

        file_put_contents($path, implode(PHP_EOL, $kept) . PHP_EOL);

        return $deleted;
    }
}
