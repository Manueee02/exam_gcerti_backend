<?php

namespace App\Http\Controllers;

use App\Models\AuditorCache;
use App\Models\UserCreatedExaminerDecisionmaker;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExaminerCacheController extends Controller
{
    use AuthorizesRequests;

    // GET /api/examiners-decisionmakers?type=examiner|decision_maker&status=qualificato
    public function index(Request $request): JsonResponse
    {
        $type   = $request->input('type');
        $status = $request->input('status'); // 'qualificato' o altro, opzionale

        $query = AuditorCache::query();

        if ($type === 'examiner') {
            $query->where('is_examiner', 'true');
        } elseif ($type === 'decision_maker') {
            $query->where('is_decision_maker', true);
        }

        if ($status === 'qualificato') {
            $query->where('has_qualified_status', true);
        }

        $auditors = $query->orderBy('surname')->orderBy('name')->get();

        // public_id usati per il check "ha già un utente creato"
        $publicIds = $auditors->pluck('public_id');
        $usersMap  = UserCreatedExaminerDecisionmaker::whereIn('auditor_public_id', $publicIds)
            ->with('user:id,name,email')
            ->get()
            ->keyBy('auditor_public_id');

        $data = $auditors->map(function ($a) use ($usersMap) {
            $userLink = $usersMap->get($a->public_id);

            return [
                'public_id'         => $a->public_id,
                'name'              => $a->name,
                'surname'           => $a->surname,
                'email'             => $a->email,
                'phone'             => $a->phone,
                'is_examiner'       => $a->is_examiner === 'true',
                'is_decision_maker' => $a->is_decision_maker,
                'qualified'         => $a->has_qualified_status,
                'has_user'          => $userLink !== null,
                'user'              => $userLink?->user ? [
                    'id'    => $userLink->user->id,
                    'name'  => $userLink->user->name,
                    'email' => $userLink->user->email,
                ] : null,
                'synced_at'         => $a->synced_at,
                'is_active' => $a->is_active,
            ];
        });

        return response()->json([
            'success' => true,
            'count'   => $data->count(),
            'data'    => $data,
        ]);
    }

    // GET /api/examiners-decisionmakers/{publicId}
    public function show(string $publicId): JsonResponse
    {
        $auditor = AuditorCache::where('public_id', $publicId)->first();

        if (!$auditor) {
            return response()->json(['success' => false, 'message' => 'Auditor non trovato in cache'], 404);
        }

        $userLink = UserCreatedExaminerDecisionmaker::where('auditor_public_id', $publicId)
            ->with('user:id,name,email')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'public_id'         => $auditor->public_id,
                'name'              => $auditor->name,
                'surname'           => $auditor->surname,
                'email'             => $auditor->email,
                'phone'             => $auditor->phone,
                'fiscal_code'       => $auditor->fiscal_code,
                'is_examiner'       => $auditor->is_examiner === 'true',
                'is_decision_maker' => $auditor->is_decision_maker,
                'qualified'         => $auditor->has_qualified_status,
                'has_user'          => $userLink !== null,
                'user'              => $userLink?->user,
                'synced_at'         => $auditor->synced_at,
                'is_active' => $auditor->is_active,

            ],
        ]);
    }
}
