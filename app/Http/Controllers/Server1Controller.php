<?php

namespace App\Http\Controllers;

use App\Services\ExaminerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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

        $response = Http::withToken(config('services.app1.token'))
            ->put(config('services.app1.url') . '/examiner/' . $id, $body);

        if (!$response->successful()) {
            return [
                'status' => $response->status(),
                'error' => 'Errore aggiornamento App 1'
            ];
        }

        return [
            'status' => $response->status(),
            'data' => $response->json()
        ];
    }

    public function updateQualificationStatus(Request $request)
    {
        $body = $request->all();

        $response = Http::withToken(config('services.app1.token'))
            ->post(config('services.app1.url') . '/examiner/qualifications/update-status', $body);

        if (!$response->successful()) {
            return [
                'status' => $response->status(),
                'error' => 'Errore aggiornamento status'
            ];
        }

        return [
            'status' => $response->status(),
            'data' => $response->json()
        ];
    }

    public function updateQualificationStatusNoIaf(Request $request)
    {
        $body = $request->all();

        $response = Http::withToken(config('services.app1.token'))
            ->post(config('services.app1.url') . '/examiner/qualifications/update-status/noIaf', $body);

        if (!$response->successful()) {
            return [
                'status' => $response->status(),
                'error' => 'Errore aggiornamento status (no IAF)'
            ];
        }

        return [
            'status' => $response->status(),
            'data' => $response->json()
        ];
    }
}
