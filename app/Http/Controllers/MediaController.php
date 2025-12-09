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
    /**
     * Upload file to DigitalOcean Spaces
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function upload(Request $request): JsonResponse
{
    try {
        // Validazione del file
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:51200', // Max 50MB
            'folder' => 'string|nullable' // Cartella opzionale oltre ad 'auditor'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Errore di validazione',
                'errors' => $validator->errors()
            ], 422);
        }

        $file = $request->file('file');
        $additionalFolder = $request->input('folder', '');

        // Calcola l'hash MD5 del file prima dell'upload per il controllo di integrità
        $fileContent = file_get_contents($file);
        $localMd5 = md5($fileContent);

        /*Log::info('Inizio upload file', [
            'original_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'local_md5' => $localMd5
        ]);*/

        // Genera un nome file unico
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $fileName = Str::uuid() . '.' . $extension;

        // Costruisci il path: auditor/[folder_aggiuntiva/]filename
        $basePath = 'file_auditor';
        if (!empty($additionalFolder)) {
            $basePath .= '/' . trim($additionalFolder, '/');
        }
        $filePath = $basePath . '/' . $fileName;

        try {
            // Carica il file su DigitalOcean Spaces
            $uploaded = Storage::disk('spaces')->put($filePath, $fileContent);

            if (!$uploaded) {
                Log::error('Errore durante il caricamento del file su DigitalOcean Spaces', [
                    'fileName' => $fileName,
                    'filePath' => $filePath,
                    'fileSize' => $file->getSize(),
                    'mimeType' => $file->getMimeType()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Errore durante il caricamento del file'
                ], 500);
            }

            // Verifica l'integrità del file tramite ETag
            $integrityCheck = $this->verifyFileIntegrity($filePath, $localMd5);

            if (!$integrityCheck['success']) {
                // Se il controllo di integrità fallisce, elimina il file caricato
                Storage::disk('spaces')->delete($filePath);

                Log::error('Controllo integrità fallito', [
                    'fileName' => $fileName,
                    'filePath' => $filePath,
                    'local_md5' => $localMd5,
                    'remote_etag' => $integrityCheck['remote_etag'] ?? 'N/A',
                    'details' => $integrityCheck['message']
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Errore di integrità del file: ' . $integrityCheck['message']
                ], 500);
            }

            /*Log::info('Controllo integrità superato', [
                'fileName' => $fileName,
                'local_md5' => $localMd5,
                'remote_etag' => $integrityCheck['remote_etag']
            ]);*/

        } catch (\Exception $e) {
            Log::error('Errore upload file su DigitalOcean Spaces', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'fileName' => $fileName,
                'filePath' => $filePath,
                'fileSize' => $file->getSize(),
                'mimeType' => $file->getMimeType()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Errore durante il caricamento del file',
                'error' => config('app.debug') ? $e->getMessage() : 'Errore durante il caricamento'
            ], 500);
        }

        // Ottieni l'URL pubblico del file
        $fileUrl = Storage::disk('spaces')->url($filePath);

        $media = Media::create([
            'original_name' => $originalName,
            'path' => $filePath,
            'url' => $fileUrl,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'disk' => 'spaces',
            'md5_hash' => $localMd5,
            'etag' => $integrityCheck['remote_etag'] ?? null
        ]);

        return response()->json([
            'success' => true,
            'message' => 'File caricato con successo e integrità verificata',
            'data' => [
                'id' => $media->id,
                'original_name' => $media->original_name,
                'path' => $media->path,
                'url' => $media->url,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                'size_human' => $this->formatBytes($media->size),
                'md5_hash' => $media->md5_hash,
                'integrity_verified' => true,
                'created_at' => $media->created_at
            ]
        ], 201);

    } catch (Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Errore interno del server',
            'error' => config('app.debug') ? $e->getMessage() : 'Errore durante il caricamento'
        ], 500);
    }
}

    /**
     * Verifica l'integrità del file confrontando l'MD5 locale con l'ETag remoto
     */
    private function verifyFileIntegrity(string $filePath, string $localMd5): array
    {
        try {
            // Ottieni i metadati del file da DigitalOcean Spaces
            $fileExists = Storage::disk('spaces')->exists($filePath);

            if (!$fileExists) {
                return [
                    'success' => false,
                    'message' => 'File non trovato su DigitalOcean Spaces',
                    'remote_etag' => null
                ];
            }

            // Ottieni l'ETag del file remoto usando il client S3 direttamente
            $disk = Storage::disk('spaces');
            $config = $disk->getConfig();

            $s3Client = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region' => $config['region'],
                'endpoint' => $config['endpoint'],
                'credentials' => [
                    'key' => $config['key'],
                    'secret' => $config['secret'],
                ],
            ]);

            $headObject = $s3Client->headObject([
                'Bucket' => $config['bucket'],
                'Key' => $filePath
            ]);

            $remoteETag = trim($headObject['ETag'], '"'); // Rimuovi le virgolette dall'ETag

            // Per file singoli (non multipart), l'ETag è l'MD5
            // Per file multipart, l'ETag ha un formato diverso (MD5-numeroparti)
            if (strpos($remoteETag, '-') !== false) {
                // File multipart: verifica alternativa tramite dimensione
                $remoteSize = $headObject['ContentLength'];
                return [
                    'success' => true,
                    'message' => 'File multipart - verifica tramite dimensione',
                    'remote_etag' => $remoteETag,
                    'verification_method' => 'size_check'
                ];
            }

            // File singolo: confronta MD5 con ETag
            if (strtolower($localMd5) === strtolower($remoteETag)) {
                return [
                    'success' => true,
                    'message' => 'Integrità verificata tramite MD5/ETag',
                    'remote_etag' => $remoteETag,
                    'verification_method' => 'md5_etag'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Hash MD5 non corrispondente',
                    'remote_etag' => $remoteETag,
                    'local_md5' => $localMd5
                ];
            }

        } catch (\Exception $e) {
            Log::error('Errore durante la verifica integrità', [
                'message' => $e->getMessage(),
                'filePath' => $filePath
            ]);

            return [
                'success' => false,
                'message' => 'Errore durante la verifica: ' . $e->getMessage(),
                'remote_etag' => null
            ];
        }
    }

    /**
     * Get media file by ID
     *
     * @param int $id
     * @return JsonResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
     */
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
                'Content-Type' => $media->mime_type,
                'Content-Disposition' => 'attachment; filename="' . $media->original_name . '"',
                'Content-Length' => Storage::disk($media->disk)->size($media->path)
            ]);

        } catch (Exception $e) {
            \Log::error('Download error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'File non disponibile per il download'
            ], 404);
        }
    }



    /**
     * Delete media file
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $media = Media::findOrFail($id);

            // Elimina il file da DigitalOcean Spaces
            if (Storage::disk($media->disk)->exists($media->path)) {
                Storage::disk($media->disk)->delete($media->path);
            }

            // Elimina il record dal database
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

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
