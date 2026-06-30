<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

class ExaminerService
{
    private const TIMEOUT_SECONDS = 30;

    public function getExaminer(string $publicId): array
    {
        $url = config('services.app1.url') . '/examiner/' . $publicId;

        try {
            $response = Http::withToken(config('services.app1.token'))
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($url);

            if (!$response->successful()) {
                Log::warning('ExaminerService::getExaminer - App1 ha risposto con errore', [
                    'public_id' => $publicId,
                    'url' => $url,
                    'status' => $response->status(),
                    'response_body' => $response->json() ?? $response->body(),
                ]);

                return [
                    'status' => $response->status(),
                    'data' => null,
                    'error' => 'App1 ha risposto con errore',
                    'app1_response' => $response->json() ?? $response->body(),
                ];
            }

            return [
                'status' => $response->status(),
                'data' => $response->json(),
                'error' => null,
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
        Log::info('URL chiamata App1', ['url' => config('services.app1.url')]);

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
                ->timeout(self::TIMEOUT_SECONDS)
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
                    'data' => null,
                    'error' => 'App1 ha risposto con errore',
                    'app1_response' => $response->json() ?? $response->body(),
                ];
            }

            return [
                'status' => $response->status(),
                'data' => $response->json(),
                'error' => null,
            ];
        } catch (ConnectionException $e) {
            Log::error('ExaminerService::getExaminers - Connessione ad App1 fallita', [
                'url' => $url,
                'query' => $query,
                'message' => $e->getMessage(),
            ]);

            return [
                'status' => 503,
                'data' => null,
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
                'data' => null,
                'error' => 'Errore interno: ' . $e->getMessage(),
            ];
        }
    }
}
