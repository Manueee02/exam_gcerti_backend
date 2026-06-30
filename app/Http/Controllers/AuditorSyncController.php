<?php

// app/Http/Controllers/Internal/AuditorSyncController.php
namespace App\Http\Controllers;


use App\Models\AuditorCache;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AuditorSyncController extends Controller
{
    use AuthorizesRequests;

    public function upsert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id'                    => 'required|integer',
            'public_id'             => 'required|uuid',
            'name'                  => 'required|string|max:200',
            'surname'               => 'required|string|max:200',
            'phone'                 => 'nullable|string|max:200',
            'email'                 => 'nullable|email|max:200',
            'fiscal_code'           => 'nullable|string|max:200',
            'type'                  => 'nullable|string|max:50',
            'is_examiner'           => 'nullable|string|max:50',
            'is_auditor'            => 'nullable|string|max:50',
            'is_decision_maker'     => 'required|boolean',
            'has_qualified_status'  => 'required|boolean',
            'access'                => 'nullable|string|max:50',
            'employee'              => 'nullable|string|max:50',
            'start_workship'        => 'nullable|date',
            'end_workship'          => 'nullable|date',
            'app1_updated_at'       => 'nullable|date',
        ]);

        $validated['synced_at'] = now();

        $record = AuditorCache::updateOrCreate(
            ['id' => $validated['id']],
            $validated
        );

        Log::info('[AuditorSyncController] Auditor sincronizzato', ['id' => $record->id]);

        return response()->json(['success' => true, 'id' => $record->id]);
    }

    // DELETE /api/internal/sync/auditor/{id}
    public function delete(int $id): JsonResponse
    {
        AuditorCache::where('id', $id)->delete();
        Log::info('[AuditorSyncController] Auditor rimosso dalla cache', ['id' => $id]);
        return response()->json(['success' => true]);
    }
}
