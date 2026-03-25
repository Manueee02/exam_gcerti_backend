<?php

namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use App\Models\Log;

class DatabaseLogger extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        try {
            Log::create([
                'level' => $record->level->getName(),
                'message' => $record->message,
                'context' => $record->context,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // fallback silenzioso (evita loop di log)
        }
    }
}
