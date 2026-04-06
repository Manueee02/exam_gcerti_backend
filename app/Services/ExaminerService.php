<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ExaminerService
{
    public function getExaminer($publicId)
    {
        $response = Http::withToken(config('services.app1.token'))
            ->get(config('services.app1.url') . '/examiner/' . $publicId);

        return [
            'status' => $response->getStatusCode(),
            'data' => $response->json()
        ];
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

        $response = Http::withToken(config('services.app1.token'))
            ->get(config('services.app1.url') . '/examiner', $query);

        if (!$response->successful()) {
            return [
                'status' => $response->status(),
                'error' => 'Errore contattando App 1'
            ];
        }

        return [
            'status' => $response->status(),
            'data' => $response->json()
        ];
    }
}
