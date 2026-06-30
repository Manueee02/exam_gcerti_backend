<?php

namespace App\Http\Controllers;

use App\Models\AuditorCache;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AuditorSyncController extends Controller
{
    // POST /api/internal/sync/auditor
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

        // Un upsert significa sempre "questo auditor è rilevante ora" → riattiva
        $validated['is_active'] = true;
        $validated['synced_at'] = now();

        $record = AuditorCache::updateOrCreate(
            ['id' => $validated['id']],
            $validated
        );

        Log::info('[AuditorSyncController] Auditor sincronizzato (attivo)', ['id' => $record->id]);

        return response()->json(['success' => true, 'id' => $record->id]);
    }

    // POST /api/internal/sync/auditor/{id}/deactivate
    // Sostituisce la vecchia delete fisica: l'auditor non è più examiner/DM
    // rilevante (oppure è stato cancellato su App1), ma il record resta
    // in cache per mantenere l'integrità referenziale con planned_exams storici.
    public function deactivate(int $id): JsonResponse
    {
        $record = AuditorCache::where('id', $id)->first();

        if (!$record) {
            // Non lo abbiamo mai avuto in cache: non c'è nulla da disattivare,
            // non è un errore (idempotente).
            Log::info('[AuditorSyncController] Deactivate su id non presente in cache', ['id' => $id]);
            return response()->json(['success' => true, 'note' => 'not_found_noop']);
        }

        $record->update(['is_active' => false, 'synced_at' => now()]);

        Log::info('[AuditorSyncController] Auditor disattivato nella cache', ['id' => $id]);

        return response()->json(['success' => true]);
    }
}
