<?php

namespace App\Http\Controllers;

use App\Services\ExaminerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

class Server1Controller extends Controller
{
    protected $examinerService;

    public function __construct(ExaminerService $examinerService)
    {
        $this->examinerService = $examinerService;
    }

    public function show($id)
    {
        $result = $this->examinerService->getExaminer($id);

        return response()->json([
            'message' => 'Dati Esaminatori',
            'data' => $result['data']
        ], $result['status']);
    }

    public function index(Request $request)
    {
        $filters = $request->only(['type', 'status']);

        $result = $this->examinerService->getExaminers($filters);

        return response()->json($result, $result['status']);
    }

    public function updateExaminer(Request $request, $id)
    {
        $body = $request->all();
        $url = config('services.app1.url') . '/examiner/' . $id;

        try {
            $response = Http::withToken(config('services.app1.token'))
                ->put($url, $body);

            if (!$response->successful()) {
                Log::warning('Server1Controller::updateExaminer - App1 ha risposto con errore', [
                    'examiner_id' => $id,
                    'url' => $url,
                    'request_body' => $body,
                    'status' => $response->status(),
                    'response_body' => $response->json() ?? $response->body(),
                ]);

                return [
                    'status' => $response->status(),
                    'error' => 'Errore aggiornamento App 1'
                ];
            }

            return [
                'status' => $response->status(),
                'data' => $response->json()
            ];
        } catch (ConnectionException $e) {
            Log::error('Server1Controller::updateExaminer - Connessione ad App1 fallita', [
                'examiner_id' => $id,
                'url' => $url,
                'request_body' => $body,
                'message' => $e->getMessage(),
            ]);

            return [
                'status' => 503,
                'error' => 'Connessione ad App 1 fallita: ' . $e->getMessage(),
            ];
        } catch (Throwable $e) {
            Log::error('Server1Controller::updateExaminer - Errore inatteso', [
                'examiner_id' => $id,
                'url' => $url,
                'request_body' => $body,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 500,
                'error' => 'Errore interno: ' . $e->getMessage(),
            ];
        }
    }

    public function updateQualificationStatus(Request $request)
    {
        $body = $request->all();
        $url = config('services.app1.url') . '/examiner/qualifications/update-status';

        try {
            $response = Http::withToken(config('services.app1.token'))
                ->post($url, $body);

            if (!$response->successful()) {
                Log::warning('Server1Controller::updateQualificationStatus - App1 ha risposto con errore', [
                    'url' => $url,
                    'request_body' => $body,
                    'status' => $response->status(),
                    'response_body' => $response->json() ?? $response->body(),
                ]);

                return [
                    'status' => $response->status(),
                    'error' => 'Errore aggiornamento status'
                ];
            }

            return [
                'status' => $response->status(),
                'data' => $response->json()
            ];
        } catch (ConnectionException $e) {
            Log::error('Server1Controller::updateQualificationStatus - Connessione ad App1 fallita', [
                'url' => $url,
                'request_body' => $body,
                'message' => $e->getMessage(),
            ]);

            return [
                'status' => 503,
                'error' => 'Connessione ad App 1 fallita: ' . $e->getMessage(),
            ];
        } catch (Throwable $e) {
            Log::error('Server1Controller::updateQualificationStatus - Errore inatteso', [
                'url' => $url,
                'request_body' => $body,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 500,
                'error' => 'Errore interno: ' . $e->getMessage(),
            ];
        }
    }

    public function updateQualificationStatusNoIaf(Request $request)
    {
        $body = $request->all();
        $url = config('services.app1.url') . '/examiner/qualifications/update-status/noIaf';

        try {
            $response = Http::withToken(config('services.app1.token'))
                ->post($url, $body);

            if (!$response->successful()) {
                Log::warning('Server1Controller::updateQualificationStatusNoIaf - App1 ha risposto con errore', [
                    'url' => $url,
                    'request_body' => $body,
                    'status' => $response->status(),
                    'response_body' => $response->json() ?? $response->body(),
                ]);

                return [
                    'status' => $response->status(),
                    'error' => 'Errore aggiornamento status (no IAF)'
                ];
            }

            return [
                'status' => $response->status(),
                'data' => $response->json()
            ];
        } catch (ConnectionException $e) {
            Log::error('Server1Controller::updateQualificationStatusNoIaf - Connessione ad App1 fallita', [
                'url' => $url,
                'request_body' => $body,
                'message' => $e->getMessage(),
            ]);

            return [
                'status' => 503,
                'error' => 'Connessione ad App 1 fallita: ' . $e->getMessage(),
            ];
        } catch (Throwable $e) {
            Log::error('Server1Controller::updateQualificationStatusNoIaf - Errore inatteso', [
                'url' => $url,
                'request_body' => $body,
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
