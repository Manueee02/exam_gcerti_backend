<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Auditor;
use App\Models\Cab;
use App\Models\Company;
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
            'auditors' => $this->searchAuditors($query),
            'cabs' => $this->searchCabs($query),
            'companies' => $this->searchCompanies($query),
            'users' => $this->searchUsers($query)
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
     * Ricerca tra gli Auditor
     *
     * @param string $query
     * @return array
     */
    private function searchAuditors(string $query): array
    {
        $auditors = Auditor::where(function (Builder $q) use ($query) {
            $q->where('id', 'LIKE', "%{$query}%")
                ->orWhere('name', 'LIKE', "%{$query}%")
                ->orWhere('surname', 'LIKE', "%{$query}%")
                ->orWhere('phone', 'LIKE', "%{$query}%")
                ->orWhere('email', 'LIKE', "%{$query}%")
                ->orWhere('fiscal_code', 'LIKE', "%{$query}%")
                ->orWhere('address', 'LIKE', "%{$query}%")
                ->orWhere('city', 'LIKE', "%{$query}%")
                ->orWhere('province', 'LIKE', "%{$query}%")
                ->orWhere('birth_date', 'LIKE', "%{$query}%")
                ->orWhere('birth_place', 'LIKE', "%{$query}%")
                ->orWhere('birth_province', 'LIKE', "%{$query}%")
                ->orWhere('postal_code', 'LIKE', "%{$query}%")
                ->orWhere('id_cab', 'LIKE', "%{$query}%")
                ->orWhere('instruction', 'LIKE', "%{$query}%")
                ->orWhere('title_of_study', 'LIKE', "%{$query}%")
                ->orWhere('type', 'LIKE', "%{$query}%")
                ->orWhere('up_data_status', 'LIKE', "%{$query}%")
                ->orWhere('consulting_experience', 'LIKE', "%{$query}%")
                ->orWhere('management_system_experience', 'LIKE', "%{$query}%")
                ->orWhere('working_experience', 'LIKE', "%{$query}%")
                ->orWhere('access', 'LIKE', "%{$query}%");
        })
            ->with(['cab', 'qualifications', 'companies'])
            ->get()
            ->map(function ($auditor) {
                return [
                    'type' => 'auditor',
                    'id' => $auditor->id,
                    'data' => $auditor->toArray()
                ];
            })
            ->toArray();

        return $auditors;
    }

    /**
     * Ricerca tra i CAB
     *
     * @param string $query
     * @return array
     */
    private function searchCabs(string $query): array
    {
        $cabs = Cab::where(function (Builder $q) use ($query) {
            $q->where('id', 'LIKE', "%{$query}%")
                ->orWhere('name', 'LIKE', "%{$query}%")
                ->orWhere('description', 'LIKE', "%{$query}%");
        })
            ->get()
            ->map(function ($cab) {
                return [
                    'type' => 'cab',
                    'id' => $cab->id,
                    'data' => $cab->toArray()
                ];
            })
            ->toArray();

        return $cabs;
    }

    /**
     * Ricerca tra le Company
     *
     * @param string $query
     * @return array
     */
    private function searchCompanies(string $query): array
    {
        $companies = Company::where(function (Builder $q) use ($query) {
            $q->where('id', 'LIKE', "%{$query}%")
                ->orWhere('name', 'LIKE', "%{$query}%")
                ->orWhere('email', 'LIKE', "%{$query}%")
                ->orWhere('phone', 'LIKE', "%{$query}%")
                ->orWhere('legal_site_address', 'LIKE', "%{$query}%")
                ->orWhere('legal_site_city', 'LIKE', "%{$query}%")
                ->orWhere('legal_site_postal_code', 'LIKE', "%{$query}%")
                ->orWhere('fiscal_code', 'LIKE', "%{$query}%")
                ->orWhere('piva', 'LIKE', "%{$query}%");
        })
            ->with(['auditors'])
            ->get()
            ->map(function ($company) {
                return [
                    'type' => 'company',
                    'id' => $company->id,
                    'data' => $company->toArray()
                ];
            })
            ->toArray();

        return $companies;
    }

    /**
     * Ricerca tra gli User
     *
     * @param string $query
     * @return array
     */
    private function searchUsers(string $query): array
    {
        $users = User::where(function (Builder $q) use ($query) {
            $q->where('id', 'LIKE', "%{$query}%")
                ->orWhere('name', 'LIKE', "%{$query}%")
                ->orWhere('email', 'LIKE', "%{$query}%")
/*                ->orWhere('role', 'LIKE', "%{$query}%")*/
                ->orWhere('first_access', 'LIKE', "%{$query}%");
        })
            ->select(['id', 'name', 'email', 'id_role', 'first_access', 'created_at', 'updated_at']) // Escludi campi sensibili
            ->with(['role'])
            ->get()
            ->map(function ($user) {
                return [
                    'type' => 'user',
                    'id' => $user->id,
                    'data' => $user->toArray()
                ];
            })
            ->toArray();

        return $users;
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
        $types = $request->get('types', ['auditors', 'cabs', 'companies', 'users']);
        $limit = $request->get('limit', 50);

        if (empty($query)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Query di ricerca richiesta',
                'data' => []
            ], 400);
        }

        $results = [];

        if (in_array('auditors', $types)) {
            $results['auditors'] = array_slice($this->searchAuditors($query), 0, $limit);
        }

        if (in_array('cabs', $types)) {
            $results['cabs'] = array_slice($this->searchCabs($query), 0, $limit);
        }

        if (in_array('companies', $types)) {
            $results['companies'] = array_slice($this->searchCompanies($query), 0, $limit);
        }

        if (in_array('users', $types)) {
            $results['users'] = array_slice($this->searchUsers($query), 0, $limit);
        }

        $totalResults = array_sum(array_map('count', $results));

        return response()->json([
            'status' => 'success',
            'query' => $query,
            'types' => $types,
            'limit' => $limit,
            'total_results' => $totalResults,
            'data' => $results
        ]);
    }
}
