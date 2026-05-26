<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Candidate;
use App\Models\Examiner;
use App\Models\DecisionsMaker;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class UtilsController extends Controller
{
    /**
     * Ricerca unificata tra tutti i modelli
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function search(Request $request): JsonResponse
    {
        $query = $request->get('q', '');

        if (empty($query)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Query di ricerca richiesta',
                'data' => []
            ], 400);
        }

        $results = [
            'candidates'      => $this->searchCandidates($query),
            'examiners'       => $this->searchExaminers($query),
            'decisionsMakers' => $this->searchDecisionsMakers($query),
            'users'           => $this->searchUsers($query),
        ];

        // Conta i risultati totali
        $totalResults = array_sum(array_map('count', $results));

        return response()->json([
            'status' => 'success',
            'query' => $query,
            'total_results' => $totalResults,
            'data' => $results
        ]);
    }

    /**
     * Ricerca tra i candidati
     *
     * @param string $query
     * @return array
     */
    private function searchCandidates(string $query): array
    {
        return Candidate::where(function (Builder $q) use ($query) {
            $q->where('name', 'ILIKE', "%{$query}%")
                ->orWhere('surname', 'ILIKE', "%{$query}%")
                ->orWhere('email', 'ILIKE', "%{$query}%")
                ->orWhere('phone', 'ILIKE', "%{$query}%")
                ->orWhere('fiscal_code', 'ILIKE', "%{$query}%")
                ->orWhere('residence_city', 'ILIKE', "%{$query}%");
        })
            ->select(['public_id', 'name', 'surname', 'email', 'phone', 'fiscal_code', 'active'])
            ->get()
            ->map(fn($c) => [
                'type'      => 'candidate',
                'public_id' => $c->public_id,
                'data'      => $c->toArray(),
            ])
            ->toArray();
    }

    /**
     * Ricerca tra gli esaminatori
     *
     * @param string $query
     * @return array
     */
    private function searchExaminers(string $query): array
    {
        return Examiner::where(function (Builder $q) use ($query) {
            $q->where('name', 'ILIKE', "%{$query}%")
                ->orWhere('surname', 'ILIKE', "%{$query}%")
                ->orWhere('email', 'ILIKE', "%{$query}%")
                ->orWhere('phone', 'ILIKE', "%{$query}%");
        })
            ->select(['public_id', 'name', 'surname', 'email', 'phone', 'active'])
            ->get()
            ->map(fn($e) => [
                'type'      => 'examiner',
                'public_id' => $e->public_id,
                'data'      => $e->toArray(),
            ])
            ->toArray();
    }

    /**
     * Ricerca tra i deliberanti
     *
     * @param string $query
     * @return array
     */
    private function searchDecisionsMakers(string $query): array
    {
        return DecisionsMaker::where(function (Builder $q) use ($query) {
            $q->where('name', 'ILIKE', "%{$query}%")
                ->orWhere('surname', 'ILIKE', "%{$query}%")
                ->orWhere('email', 'ILIKE', "%{$query}%")
                ->orWhere('phone', 'ILIKE', "%{$query}%");
        })
            ->select(['public_id', 'name', 'surname', 'email', 'phone', 'active'])
            ->get()
            ->map(fn($d) => [
                'type'      => 'decisionsMaker',
                'public_id' => $d->public_id,
                'data'      => $d->toArray(),
            ])
            ->toArray();
    }

    /**
     * Ricerca tra gli User
     *
     * @param string $query
     * @return array
     */
    private function searchUsers(string $query): array
    {
        return User::where(function (Builder $q) use ($query) {
            $q->where('name', 'ILIKE', "%{$query}%")
                ->orWhere('email', 'ILIKE', "%{$query}%");
        })
            ->select(['id', 'name', 'email', 'id_role', 'first_access', 'created_at', 'updated_at'])
            ->with(['role'])
            ->get()
            ->map(fn($u) => [
                'type' => 'user',
                'id'   => $u->id,
                'data' => $u->toArray(),
            ])
            ->toArray();
    }

    /**
     * Ricerca avanzata con filtri per tipologia
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function advancedSearch(Request $request): JsonResponse
    {
        $query = $request->get('q', '');
        $types = $request->get('types', ['candidates', 'examiners', 'decisionsMakers', 'users']);
        $limit = $request->get('limit', 50);

        if (empty($query)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Query di ricerca richiesta',
                'data'    => []
            ], 400);
        }

        $results = [];

        if (in_array('candidates', $types)) {
            $results['candidates'] = array_slice($this->searchCandidates($query), 0, $limit);
        }
        if (in_array('examiners', $types)) {
            $results['examiners'] = array_slice($this->searchExaminers($query), 0, $limit);
        }
        if (in_array('decisionsMakers', $types)) {
            $results['decisionsMakers'] = array_slice($this->searchDecisionsMakers($query), 0, $limit);
        }
        if (in_array('users', $types)) {
            $results['users'] = array_slice($this->searchUsers($query), 0, $limit);
        }

        $totalResults = array_sum(array_map('count', $results));

        return response()->json([
            'status'        => 'success',
            'query'         => $query,
            'types'         => $types,
            'limit'         => $limit,
            'total_results' => $totalResults,
            'data'          => $results
        ]);
    }
}
