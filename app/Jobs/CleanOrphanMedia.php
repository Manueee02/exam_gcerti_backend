<?php

namespace App\Jobs;

use App\Models\Media;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanOrphanMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Minuti dopo i quali un file orfano viene considerato scaduto.
     * Default: 60 minuti.
     */
    public function __construct(private int $expiryMinutes = 60) {}

    public function handle(): void
    {
        $this->restoreOrphanPendingDeletes();
        $this->deleteOrphanTemporaries();
    }

    /**
     * Ripristina i file marcati pending_delete che non sono stati confermati
     * entro il tempo di scadenza (l'utente è uscito senza salvare).
     * Rimuove semplicemente il flag pending_delete_at — il file su Spaces è intatto.
     */
    private function restoreOrphanPendingDeletes(): void
    {
        try {
            $count = Media::orphanPendingDelete($this->expiryMinutes)
                ->update(['pending_delete_at' => null]);

            if ($count > 0) {
                Log::info("[CleanOrphanMedia] Ripristinati {$count} file pending_delete orfani");
            }

        } catch (\Exception $e) {
            Log::error("[CleanOrphanMedia] Errore nel ripristino pending_delete: " . $e->getMessage());
        }
    }

    /**
     * Elimina i file temporanei orfani (caricati ma mai confermati con confirm-uploads)
     * sia da Spaces che dal DB.
     */
    private function deleteOrphanTemporaries(): void
    {
        try {
            $medias = Media::orphanTemporary($this->expiryMinutes)->get();

            $deleted = 0;
            foreach ($medias as $media) {
                try {
                    if (Storage::disk($media->disk)->exists($media->path)) {
                        Storage::disk($media->disk)->delete($media->path);
                    }
                    $media->delete();
                    $deleted++;
                } catch (\Exception $e) {
                    Log::error("[CleanOrphanMedia] Errore eliminazione file temporaneo ID {$media->id}: " . $e->getMessage());
                }
            }

            if ($deleted > 0) {
                Log::info("[CleanOrphanMedia] Eliminati {$deleted} file temporanei orfani");
            }

        } catch (\Exception $e) {
            Log::error("[CleanOrphanMedia] Errore nel cleanup temporanei: " . $e->getMessage());
        }
    }
}
