<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ExaminerService
{
    public function getExaminer($id)
    {
        $response = Http::withToken(config('services.app1.token'))
            ->get(config('services.app1.url') . '/examiner/' . $id);

        return [
            'status' => $response->getStatusCode(),
            'data' => $response->json()
        ];
    }
}
