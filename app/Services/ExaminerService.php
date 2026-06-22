<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

class ExaminerService
{
    public function getExaminer($publicId)
    {
        $url = config('services.app1.url') . '/examiner/' . $publicId;

        try {
            $response = Http::withToken(config('services.app1.token'))
                ->get($url);

            if (!$response->successful()) {
                Log::warning('ExaminerService::getExaminer - App1 ha risposto con errore', [
                    'public_id' => $publicId,
                    'url' => $url,
                    'status' => $response->status(),
                    'response_body' => $response->json() ?? $response->body(),
                ]);
            }

            return [
                'status' => $response->getStatusCode(),
                'data' => $response->json()
            ];
        } catch (ConnectionException $e) {
            Log::error('ExaminerService::getExaminer - Connessione ad App1 fallita', [
                'public_id' => $publicId,
                'url' => $url,
                'message' => $e->getMessage(),
            ]);

            return [
                'status' => 503,
                'data' => null,
                'error' => 'Connessione ad App 1 fallita: ' . $e->getMessage(),
            ];
        } catch (Throwable $e) {
            Log::error('ExaminerService::getExaminer - Errore inatteso', [
                'public_id' => $publicId,
                'url' => $url,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 500,
                'data' => null,
                'error' => 'Errore interno: ' . $e->getMessage(),
            ];
        }
    }

    public function getExaminers(array $filters = []): array
    {
        $query = [];

        if (isset($filters['type'])) {
            $query['type'] = $filters['type'];
        }

        if (isset($filters['status'])) {
            $query['status'] = $filters['status'];
        }

        $url = config('services.app1.url') . '/examiner';

        try {
            $response = Http::withToken(config('services.app1.token'))
                ->get($url, $query);

            if (!$response->successful()) {
                Log::warning('ExaminerService::getExaminers - App1 ha risposto con errore', [
                    'url' => $url,
                    'query' => $query,
                    'status' => $response->status(),
                    'response_body' => $response->json() ?? $response->body(),
                ]);

                return [
                    'status' => $response->status(),
                    'error' => 'Errore contattando App 1'
                ];
            }

            return [
                'status' => $response->status(),
                'data' => $response->json()
            ];
        } catch (ConnectionException $e) {
            Log::error('ExaminerService::getExaminers - Connessione ad App1 fallita', [
                'url' => $url,
                'query' => $query,
                'message' => $e->getMessage(),
            ]);

            return [
                'status' => 503,
                'error' => 'Connessione ad App 1 fallita: ' . $e->getMessage(),
            ];
        } catch (Throwable $e) {
            Log::error('ExaminerService::getExaminers - Errore inatteso', [
                'url' => $url,
                'query' => $query,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 500,
                'error' => 'Errore interno: ' . $e->getMessage(),
            ];
        }
    }
}
