<?php

namespace App\Services;

use App\Models\Auditor;
use App\Models\Qualification;
use App\Models\QualificationDocument;
use App\Models\AuditorDocument;
use App\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Exception;

class AuditorService
{
    public function deleteAuditor(int $id): array
    {
        DB::beginTransaction();
        try {
            $auditor = Auditor::with([
                'qualifications.documents.media',
                'documents.media'
            ])->findOrFail($id);

            // 🔹 1. Elimina i file associati alle qualifiche
            foreach ($auditor->qualifications as $qualification) {
                foreach ($qualification->documents as $doc) {
                    if ($doc->media) {
                        $this->deleteMediaFile($doc->media);
                        $doc->media->delete();
                    }
                    $doc->delete();
                }
                $qualification->delete();
            }

            // 🔹 2. Elimina i file associati ai documenti dell'auditor
            foreach ($auditor->documents as $doc) {
                if ($doc->media) {
                    $this->deleteMediaFile($doc->media);
                    $doc->media->delete();
                }
                $doc->delete();
            }

            // 🔹 3. Elimina le relazioni (pivot)
            $auditor->knowledgeAbilities()->detach();
            $auditor->companies()->detach();

            // 🔹 4. Elimina l'auditor
            $auditor->delete();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Auditor e dati associati eliminati con successo'
            ];
        } catch (Exception $e) {
            DB::rollBack();
            return [
                'success' => false,
                'message' => 'Errore durante l\'eliminazione dell\'auditor',
                'error' => config('app.debug') ? $e->getMessage() : null
            ];
        }
    }

    private function deleteMediaFile(Media $media): void
    {
        if (Storage::disk($media->disk)->exists($media->path)) {
            Storage::disk($media->disk)->delete($media->path);
        }
    }
}
