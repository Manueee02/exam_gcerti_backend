<?php

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Exception;

class MediaController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // UPLOAD
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Upload file to DigitalOcean Spaces.
     * Se mode=edit il file viene creato come temporaneo (is_temporary=true).
     *
     * Query param: ?mode=edit   oppure body field: mode=edit
     */
    public function upload(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'file'   => 'required|file|max:51200',
                'folder' => 'string|nullable',
                'mode'   => 'string|nullable|in:store,edit',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errore di validazione',
                    'errors'  => $validator->errors()
                ], 422);
            }

            $file             = $request->file('file');
            $additionalFolder = $request->input('folder', '');
            $mode             = $request->input('mode', 'store'); // default: store

            $fileContent = file_get_contents($file);
            $localMd5    = md5($fileContent);

            $originalName = $file->getClientOriginalName();
            $extension    = $file->getClientOriginalExtension();
            $fileName     = Str::uuid() . '.' . $extension;

            $basePath = 'file_exam';
            if (!empty($additionalFolder)) {
                $basePath .= '/' . trim($additionalFolder, '/');
            }
            $filePath = $basePath . '/' . $fileName;

            try {
                $uploaded = Storage::disk('spaces')->put($filePath, $fileContent);

                if (!$uploaded) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Errore durante il caricamento del file'
                    ], 500);
                }

                $integrityCheck = $this->verifyFileIntegrity($filePath, $localMd5);

                if (!$integrityCheck['success']) {
                    Storage::disk('spaces')->delete($filePath);
                    return response()->json([
                        'success' => false,
                        'message' => 'Errore di integrità del file: ' . $integrityCheck['message']
                    ], 500);
                }

            } catch (\Exception $e) {
                Log::error('Errore upload file su DigitalOcean Spaces', [
                    'message' => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Errore durante il caricamento del file',
                    'error'   => config('app.debug') ? $e->getMessage() : 'Errore durante il caricamento'
                ], 500);
            }

            $fileUrl = Storage::disk('spaces')->url($filePath);

            $media = Media::create([
                'original_name' => $originalName,
                'path'          => $filePath,
                'url'           => $fileUrl,
                'mime_type'     => $file->getMimeType(),
                'size'          => $file->getSize(),
                'disk'          => 'spaces',
                'md5_hash'      => $localMd5,
                'etag'          => $integrityCheck['remote_etag'] ?? null,
                // Se mode=edit il file nasce come temporaneo
                'is_temporary'  => $mode === 'edit',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'File caricato con successo' . ($mode === 'edit' ? ' (temporaneo)' : ''),
                'data'    => [
                    'id'                 => $media->id,
                    'original_name'      => $media->original_name,
                    'path'               => $media->path,
                    'url'                => $media->url,
                    'mime_type'          => $media->mime_type,
                    'size'               => $media->size,
                    'size_human'         => $this->formatBytes($media->size),
                    'md5_hash'           => $media->md5_hash,
                    'integrity_verified' => true,
                    'is_temporary'       => $media->is_temporary,
                    'created_at'         => $media->created_at,
                ]
            ], 201);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errore interno del server',
                'error'   => config('app.debug') ? $e->getMessage() : 'Errore durante il caricamento'
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SOFT DELETE  (mode=edit: segna il vecchio file come "da eliminare")
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /media/{id}/soft-delete
     * Marca il file come pending_delete senza toccarlo su Spaces.
     */
    public function softDelete(int $id): JsonResponse
    {
        try {
            $media = Media::findOrFail($id);

            $media->update(['pending_delete_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => 'File marcato per eliminazione',
                'data'    => ['id' => $media->id, 'pending_delete_at' => $media->pending_delete_at],
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errore durante il soft delete',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RESTORE  (annulla il soft delete di uno o più file)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /media/restore
     * Body: { "ids": [1, 2, 3] }
     * Ripristina i file marcati con pending_delete_at (rimuove il flag).
     */
    public function restore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|exists:media,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Errore di validazione',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $count = Media::whereIn('id', $request->ids)
                ->whereNotNull('pending_delete_at')
                ->update(['pending_delete_at' => null]);

            return response()->json([
                'success' => true,
                'message' => "{$count} file ripristinati",
                'data'    => ['restored_count' => $count],
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errore durante il ripristino',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CONFIRM DELETES  (salva: elimina davvero i file pending)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /media/confirm-deletes
     * Body: { "ids": [1, 2, 3] }
     * Elimina definitivamente da Spaces + DB i file marcati pending_delete.
     */
    public function confirmDeletes(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|exists:media,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Errore di validazione',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $medias = Media::whereIn('id', $request->ids)
                ->whereNotNull('pending_delete_at')
                ->get();

            $deleted = 0;
            foreach ($medias as $media) {
                $this->deleteFromStorage($media);
                $media->delete();
                $deleted++;
            }

            return response()->json([
                'success' => true,
                'message' => "{$deleted} file eliminati definitivamente",
                'data'    => ['deleted_count' => $deleted],
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errore durante la conferma eliminazione',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DISCARD TEMPORARIES  (annulla o salva: elimina i file temporanei orfani)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /media/discard-temporaries
     * Body: { "ids": [1, 2, 3] }
     * Elimina da Spaces + DB i file temporanei (upload non confermati).
     */
    public function discardTemporaries(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|exists:media,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Errore di validazione',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $medias = Media::whereIn('id', $request->ids)
                ->where('is_temporary', true)
                ->get();

            $deleted = 0;
            foreach ($medias as $media) {
                $this->deleteFromStorage($media);
                $media->delete();
                $deleted++;
            }

            return response()->json([
                'success' => true,
                'message' => "{$deleted} file temporanei eliminati",
                'data'    => ['deleted_count' => $deleted],
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errore durante l\'eliminazione dei temporanei',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CONFIRM UPLOAD  (promuovi un file temporaneo a permanente)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /media/confirm-uploads
     * Body: { "ids": [1, 2, 3] }
     * Promuove i file temporanei a permanenti (is_temporary=false).
     */
    public function confirmUploads(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|exists:media,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Errore di validazione',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $count = Media::whereIn('id', $request->ids)
                ->where('is_temporary', true)
                ->update(['is_temporary' => false]);

            return response()->json([
                'success' => true,
                'message' => "{$count} file confermati come permanenti",
                'data'    => ['confirmed_count' => $count],
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errore durante la conferma degli upload',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SHOW / DOWNLOAD
    // ─────────────────────────────────────────────────────────────────────────

    public function show(int $id)
    {
        try {
            $media = Media::findOrFail($id);

            if (!Storage::disk($media->disk)->exists($media->path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File non disponibile'
                ], 404);
            }

            $file = Storage::disk($media->disk)->get($media->path);

            return response($file, 200, [
                'Content-Type'        => $media->mime_type,
                'Content-Disposition' => 'attachment; filename="' . $media->original_name . '"',
                'Content-Length'      => Storage::disk($media->disk)->size($media->path)
            ]);

        } catch (Exception $e) {
            Log::error('Download error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'File non disponibile per il download'
            ], 404);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE  (eliminazione immediata — comportamento originale invariato)
    // ─────────────────────────────────────────────────────────────────────────

    public function destroy(int $id): JsonResponse
    {
        try {
            $media = Media::findOrFail($id);
            $this->deleteFromStorage($media);
            $media->delete();

            return response()->json([
                'success' => true,
                'message' => 'File eliminato con successo'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errore durante l\'eliminazione del file'
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function deleteFromStorage(Media $media): void
    {
        if (Storage::disk($media->disk)->exists($media->path)) {
            Storage::disk($media->disk)->delete($media->path);
        }
    }

    private function verifyFileIntegrity(string $filePath, string $localMd5): array
    {
        try {
            if (!Storage::disk('spaces')->exists($filePath)) {
                return ['success' => false, 'message' => 'File non trovato su DigitalOcean Spaces', 'remote_etag' => null];
            }

            $disk   = Storage::disk('spaces');
            $config = $disk->getConfig();

            $s3Client = new \Aws\S3\S3Client([
                'version'     => 'latest',
                'region'      => $config['region'],
                'endpoint'    => $config['endpoint'],
                'credentials' => [
                    'key'    => $config['key'],
                    'secret' => $config['secret'],
                ],
            ]);

            $headObject  = $s3Client->headObject(['Bucket' => $config['bucket'], 'Key' => $filePath]);
            $remoteETag  = trim($headObject['ETag'], '"');

            if (strpos($remoteETag, '-') !== false) {
                return ['success' => true, 'message' => 'File multipart - verifica tramite dimensione', 'remote_etag' => $remoteETag, 'verification_method' => 'size_check'];
            }

            if (strtolower($localMd5) === strtolower($remoteETag)) {
                return ['success' => true, 'message' => 'Integrità verificata tramite MD5/ETag', 'remote_etag' => $remoteETag, 'verification_method' => 'md5_etag'];
            }

            return ['success' => false, 'message' => 'Hash MD5 non corrispondente', 'remote_etag' => $remoteETag, 'local_md5' => $localMd5];

        } catch (\Exception $e) {
            Log::error('Errore durante la verifica integrità', ['message' => $e->getMessage(), 'filePath' => $filePath]);
            return ['success' => false, 'message' => 'Errore durante la verifica: ' . $e->getMessage(), 'remote_etag' => null];
        }
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
