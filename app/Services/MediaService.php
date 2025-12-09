<?php


namespace App\Services;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Exception;

class MediaService
{
    public function deleteMedia(int $id): array
    {
        try {
            $media = Media::findOrFail($id);

            if (Storage::disk($media->disk)->exists($media->path)) {
                Storage::disk($media->disk)->delete($media->path);
            }

            $media->delete();

            return [
                'success' => true,
                'message' => 'File eliminato con successo',
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Errore durante l\'eliminazione del file',
            ];
        }
    }
}
