<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\CandidateCompany;
use App\Models\CandidateMedia;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CandidateController extends Controller
{
    use AuthorizesRequests;

    private function decodificaCodiceFiscale($codiceFiscale): ?array
    {
        $codiceFiscale = strtoupper(trim($codiceFiscale));

        if (strlen($codiceFiscale) !== 16) {
            Log::warning('[CandidateController] Codice fiscale con lunghezza non valida.', [
                'codice_fiscale' => $codiceFiscale,
                'lunghezza'      => strlen($codiceFiscale),
            ]);
            return null;
        }

        try {
            $comuniPath = base_path('database/comuni.json');

            if (!file_exists($comuniPath)) {
                Log::error('[CandidateController] File comuni.json non trovato.', [
                    'path' => $comuniPath,
                ]);
                throw new \Exception('File comuni.json non trovato');
            }

            $comuniJson = file_get_contents($comuniPath);
            $comuni     = json_decode($comuniJson, true);

            if (!$comuni) {
                Log::error('[CandidateController] Errore nel parsing del file comuni.json.', [
                    'path' => $comuniPath,
                ]);
                throw new \Exception('Errore nel parsing del file comuni.json');
            }

            $cognome          = substr($codiceFiscale, 0, 3);
            $nome             = substr($codiceFiscale, 3, 3);
            $annoNascita      = substr($codiceFiscale, 6, 2);
            $meseNascita      = substr($codiceFiscale, 8, 1);
            $giornoSesso      = substr($codiceFiscale, 9, 2);
            $codiceCatastale  = substr($codiceFiscale, 11, 4);
            $carattereControllo = substr($codiceFiscale, 15, 1);

            $giorno = intval($giornoSesso);
            $sesso  = 'M';

            if ($giorno > 31) {
                $sesso  = 'F';
                $giorno = $giorno - 40;
            }

            $mesi = [
                'A' => '01',
                'B' => '02',
                'C' => '03',
                'D' => '04',
                'E' => '05',
                'H' => '06',
                'L' => '07',
                'M' => '08',
                'P' => '09',
                'R' => '10',
                'S' => '11',
                'T' => '12',
            ];

            if (!isset($mesi[$meseNascita])) {
                Log::warning('[CandidateController] Carattere mese non valido nel codice fiscale.', [
                    'codice_fiscale' => $codiceFiscale,
                    'carattere_mese' => $meseNascita,
                ]);
                return null;
            }

            $mese        = $mesi[$meseNascita];
            $annoCompleto = intval($annoNascita);
            $annoCompleto += ($annoCompleto <= 30) ? 2000 : 1900;

            $comuneNascita = null;
            foreach ($comuni as $comune) {
                if ($comune['codiceCatastale'] === $codiceCatastale) {
                    $comuneNascita = $comune;
                    break;
                }
            }

            if (!$comuneNascita) {
                Log::warning('[CandidateController] Codice catastale non trovato tra i comuni italiani — potrebbe essere estero.', [
                    'codice_fiscale'   => $codiceFiscale,
                    'codice_catastale' => $codiceCatastale,
                ]);
            }

            $luogoNascita = $comuneNascita
                ? $comuneNascita['nome']
                : 'Paese estero (codice: ' . $codiceCatastale . ')';
            $provincia    = $comuneNascita ? $comuneNascita['sigla'] : null;
            $dataNascita  = sprintf('%04d-%02d-%02d', $annoCompleto, intval($mese), $giorno);

            if (!checkdate(intval($mese), $giorno, $annoCompleto)) {
                Log::warning('[CandidateController] Data di nascita non valida estratta dal codice fiscale.', [
                    'codice_fiscale' => $codiceFiscale,
                    'data_calcolata' => $dataNascita,
                ]);
                return null;
            }

            return [
                'codice_fiscale'   => $codiceFiscale,
                'data_nascita'     => $dataNascita,
                'sesso'            => $sesso,
                'luogo_nascita'    => $luogoNascita,
                'provincia_nascita' => $provincia,
                'anno_nascita'     => $annoCompleto,
                'mese_nascita'     => intval($mese),
                'giorno_nascita'   => $giorno,
            ];
        } catch (\Exception $e) {
            Log::error('[CandidateController] Eccezione durante la decodifica del codice fiscale.', [
                'codice_fiscale' => $codiceFiscale,
                'exception'      => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    // =========================================================================
    // ESTRAI DATI COMUNI
    // =========================================================================
    private function estraiDati()
    {
        $comuniPath = base_path('database/comuni.json');

        if (!file_exists($comuniPath)) {
            Log::error('[CandidateController] File comuni.json non trovato in estraiDati.', [
                'path' => $comuniPath,
            ]);
            throw new \Exception('File comuni.json non trovato');
        }

        $comuniJson = file_get_contents($comuniPath);
        $comuni     = json_decode($comuniJson, true);

        if (!$comuni || !is_array($comuni)) {
            Log::error('[CandidateController] Formato JSON non valido nel file comuni.json.', [
                'path' => $comuniPath,
            ]);
            return response()->json(['error' => 'Formato JSON non valido'], 400);
        }

        $result = [];

        foreach ($comuni as $comune) {
            if (
                isset($comune['popolazione'], $comune['sigla'], $comune['provincia']['nome']) &&
                $comune['popolazione'] > 10000
            ) {
                $sigla = $comune['sigla'];

                if (!isset($result[$sigla])) {
                    $result[$sigla] = ['provincia' => $sigla, 'citta' => []];
                }

                $result[$sigla]['citta'][] = [
                    'nome' => $comune['nome'],
                    'cap'  => $comune['cap'] ?? [],
                ];
            }
        }

        return response()->json($result);
    }

    public function getAllData(): \Illuminate\Http\JsonResponse
    {
        try {
            $citta = $this->estraiDati();
            return response()->json(['data' => ['citta' => $citta]], 200);
        } catch (\Exception $e) {
            Log::error('[CandidateController] Errore nel recupero dei dati città/comuni.', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error'   => 'Errore nel recupero dei dati',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // =========================================================================
    // INDEX
    // =========================================================================
    public function index(): \Illuminate\Http\JsonResponse
    {
        try {
            $candidates = Candidate::where('active', 'true')->get();

            $mappedCandidates = $candidates->map(fn($c) => [
                'public_id'          => $c->public_id,
                'name'               => $c->name,
                'surname'            => $c->surname,
                'email'              => $c->email,
                'phone'              => $c->phone,
                'sex'                => $c->sex,
                'birthdate'          => $c->birthdate,
                'birthplace'         => $c->birthplace,
                'birthprovince'      => $c->birthprovince,
                'birthcommun'        => $c->birthcommun,
                'birthcountry'       => $c->birthcountry,
                'residence_address'  => $c->residence_address,
                'residence_city'     => $c->residence_city,
                'residence_province' => $c->residence_province,
                'residence_country'  => $c->residence_country,
                'residence_zip'      => $c->residence_zip,
                'fiscal_code'        => $c->fiscal_code,
                'is_foreign'         => $c->is_foreign,
                'active'             => $c->active,
                'created_at'         => $c->created_at,
                'updated_at'         => $c->updated_at,
            ]);

            return response()->json([
                'success'    => true,
                'count'      => $mappedCandidates->count(),
                'candidates' => $mappedCandidates,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('[CandidateController] Errore nel recupero della lista candidati.', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Errore nel recupero dei candidati.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // =========================================================================
    // SHOW
    // =========================================================================
    public function show(string $id): \Illuminate\Http\JsonResponse
    {
        try {
            $candidate = Candidate::with(['companies', 'media.media'])
                ->where('public_id', $id)
                ->firstOrFail();

            $this->authorize('view', $candidate);

            $mappedCandidate = [
                'public_id'          => $candidate->public_id,
                'name'               => $candidate->name,
                'surname'            => $candidate->surname,
                'email'              => $candidate->email,
                'phone'              => $candidate->phone,
                'sex'                => $candidate->sex,
                'birthdate'          => $candidate->birthdate,
                'birthplace'         => $candidate->birthplace,
                'birthprovince'      => $candidate->birthprovince,
                'birthcommun'        => $candidate->birthcommun,
                'birthcountry'       => $candidate->birthcountry,
                'residence_address'  => $candidate->residence_address,
                'residence_city'     => $candidate->residence_city,
                'residence_province' => $candidate->residence_province,
                'residence_country'  => $candidate->residence_country,
                'residence_zip'      => $candidate->residence_zip,
                'fiscal_code'        => $candidate->fiscal_code,
                'is_foreign'         => $candidate->is_foreign,
                'active'             => $candidate->active,
                'companies'          => $candidate->companies->map(fn($co) => [
                    'id_candidates' => $co->id_candidates,
                    'billing_type'  => $co->billing_type,
                    'piva'          => $co->piva,
                    'company_piva'  => $co->company_piva,
                    'created_at'    => $co->created_at,
                    'updated_at'    => $co->updated_at,
                ]),
                'media'      => $candidate->media,
                'created_at' => $candidate->created_at,
                'updated_at' => $candidate->updated_at,
            ];

            return response()->json(['success' => true, 'candidate' => $mappedCandidate], 200);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            Log::warning('[CandidateController] Accesso non autorizzato al candidato.', [
                'public_id' => $id,
                'user_id'   => auth()->id(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Non autorizzato a visualizzare questo candidato.',
            ], 403);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::warning('[CandidateController] Candidato non trovato in show.', [
                'public_id' => $id,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Candidato non trovato.',
            ], 404);
        } catch (\Throwable $e) {
            Log::error('[CandidateController] Errore durante il recupero del candidato.', [
                'public_id' => $id,
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Errore durante il recupero del candidato.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // =========================================================================
    // STORE
    // =========================================================================
    public function store(Request $request)
    {
        $baseRules = [
            'name'               => ['required', 'string', 'max:255'],
            'surname'            => ['required', 'string', 'max:255'],
            'phone'              => ['required', 'string', 'max:50'],
            'fiscal_code'        => ['required', 'string', 'max:16'],
            'is_foreign'         => ['nullable', 'string'],
            'birthcountry'       => ['nullable', 'string', 'max:255'],
            'residence_address'  => ['required', 'string', 'max:255'],
            'residence_city'     => ['required', 'string', 'max:255'],
            'residence_province' => ['required_if:is_foreign,0,false', 'nullable', 'string', 'max:10'],
            'residence_zip'      => ['required_if:is_foreign,0,false', 'nullable', 'string', 'max:10'],
            'residence_country'  => ['required', 'string', 'max:255'],
            'billing_type'       => ['required', 'string', 'in:personal,freelancer,company'],
            'media'              => ['required', 'array', 'min:2'],
            'media.*.id_media'   => ['required', 'integer', 'exists:media,id'],
            'media.*.type'       => ['required', 'string', 'in:fiscal_code,id_document,curriculum'],
            'id_gdpr'            => ['required', 'string', 'exists:GDPR,public_id'],
        ];

        $validator = Validator::make($request->all(), $baseRules);
        if ($validator->fails()) {
            Log::warning('[CandidateController] Validazione fallita in store.', [
                'user_id' => auth()->id(),
                'errors'  => $validator->errors()->toArray(),
                'input'   => $request->except(['media']),
            ]);
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $billingType  = $request->input('billing_type');
        $billingError = $this->validateBilling($request, $billingType);
        if ($billingError) {
            Log::warning('[CandidateController] Validazione billing fallita in store.', [
                'user_id'      => auth()->id(),
                'billing_type' => $billingType,
                'errors'       => $billingError,
            ]);
            return response()->json(['success' => false, 'errors' => $billingError], 422);
        }

        $mediaItems = collect($request->input('media', []));
        $mediaError = $this->validateMedia($mediaItems);
        if ($mediaError) {
            Log::warning('[CandidateController] Validazione media fallita in store.', [
                'user_id' => auth()->id(),
                'errors'  => $mediaError,
                'media'   => $mediaItems->toArray(),
            ]);
            return response()->json(['success' => false, 'errors' => $mediaError], 422);
        }

        try {
            $fiscalCode = $request->input('fiscal_code');
            $decoded    = $this->decodificaCodiceFiscale($fiscalCode);

            if (!$decoded) {
                Log::warning('[CandidateController] Codice fiscale non decodificabile in store.', [
                    'user_id'      => auth()->id(),
                    'fiscal_code'  => $fiscalCode,
                ]);
                return response()->json([
                    'success' => false,
                    'errors'  => ['fiscal_code' => ['Codice fiscale non valido o non decodificabile']],
                ], 422);
            }

            $gdpr = \App\Models\GDPR::where('public_id', $request->input('id_gdpr'))->firstOrFail();

            DB::beginTransaction();

            $user      = auth()->user();
            $candidate = Candidate::create([
                'id_user'            => $user->id,
                'name'               => $request->input('name'),
                'surname'            => $request->input('surname'),
                'email'              => $user->email,
                'phone'              => $request->input('phone'),
                'fiscal_code'        => $fiscalCode,
                'sex'                => $decoded['sesso'],
                'birthdate'          => $decoded['data_nascita'],
                'birthplace'         => $decoded['luogo_nascita'],
                'birthprovince'      => $decoded['provincia_nascita'],
                'birthcommun'        => $decoded['luogo_nascita'],
                'is_foreign'         => $request->input('is_foreign'),
                'birthcountry'       => $request->input('birthcountry'),
                'residence_address'  => $request->input('residence_address'),
                'residence_city'     => $request->input('residence_city'),
                'residence_province' => $request->input('residence_province'),
                'residence_zip'      => $request->input('residence_zip'),
                'residence_country'  => $request->input('residence_country'),
                'active'             => 'true',
            ]);

            $candidate->refresh();

            $this->syncCompany($candidate->id, $billingType, $request);
            $this->syncMedia($candidate->id, $mediaItems);

            \App\Models\GDPRSigned::create([
                'id_GDPR'      => $gdpr->id,
                'id_candidate' => $candidate->id,
                'id_user'      => $user->id,
                'id_exam'      => null,
                'accepted_at'  => now(),
                'accepted'     => 'true',
                'date'         => now()->toDateString(),
            ]);

            $user->candidate_registration_completed = 'true';
            $user->save();

            DB::commit();

            Log::info('[CandidateController] Candidato creato con successo.', [
                'user_id'    => $user->id,
                'public_id'  => $candidate->public_id,
                'fiscal_code' => $fiscalCode,
            ]);

            return response()->json([
                'success'   => true,
                'message'   => 'Candidato creato con successo.',
                'candidate' => ['public_id' => $candidate->public_id],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[CandidateController] Errore durante la creazione del candidato.', [
                'user_id'   => auth()->id(),
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Errore durante la creazione del candidato.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // =========================================================================
    // UPDATE
    // =========================================================================
    public function update(Request $request, string $id)
    {
        $candidate = Candidate::where('public_id', $id)->first();

        if (!$candidate) {
            Log::warning('[CandidateController] Candidato non trovato in update.', [
                'public_id' => $id,
                'user_id'   => auth()->id(),
            ]);
            return response()->json(['success' => false, 'message' => 'Candidato non trovato.'], 404);
        }

        $rules = [
            'name'                          => ['required', 'string', 'max:255'],
            'surname'                       => ['required', 'string', 'max:255'],
            'phone'                         => ['required', 'string', 'max:50'],
            'fiscal_code'                   => ['required', 'string', 'max:16'],
            'residence_address'             => ['required', 'string', 'max:255'],
            'residence_city'                => ['required', 'string', 'max:255'],
            'residence_country'             => ['required', 'string', 'max:255'],
            'company.billing_type'          => ['required', 'string', 'in:personal,freelancer,company'],
            'company.company_piva'          => ['nullable', 'string', 'max:11'],
            'company.company_social_reason' => ['nullable', 'string', 'max:255'],
            'company.company_mail'          => ['nullable', 'email'],
            'company.company_province'      => ['nullable', 'string', 'max:255'],
            'company.company_legal_address' => ['nullable', 'string', 'max:255'],
            'company.company_city'          => ['nullable', 'string', 'max:255'],
            'company.company_phone'         => ['nullable', 'string', 'max:50'],
            'company.company_zip'           => ['nullable', 'string', 'max:10'],
            'company.is_foreign_company'    => ['nullable', 'string', 'in:true,false'],
            'media'                         => ['required', 'array', 'min:2'],
            'media.*.id_media'              => ['required', 'integer', 'exists:media,id'],
            'media.*.type'                  => ['required', 'string', 'in:fiscal_code,id_document,curriculum'],
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            Log::warning('[CandidateController] Validazione fallita in update.', [
                'public_id' => $id,
                'user_id'   => auth()->id(),
                'errors'    => $validator->errors()->toArray(),
            ]);
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();

        try {
            $candidate->fill($request->only([
                'name',
                'surname',
                'phone',
                'fiscal_code',
                'sex',
                'birthdate',
                'birthplace',
                'birthprovince',
                'birthcommun',
                'residence_address',
                'residence_city',
                'residence_province',
                'residence_zip',
                'residence_country',
                'is_foreign',
                'birthcountry',
            ]));
            $candidate->save();

            $billingType = $request->input('company.billing_type')
                ?? $request->input('billing_type');

            $this->syncCompany($candidate->id, $billingType, $request, true);

            $candidate->media()->delete();
            $this->syncMedia($candidate->id, collect($request->input('media')));

            DB::commit();

            Log::info('[CandidateController] Candidato aggiornato con successo.', [
                'public_id' => $id,
                'user_id'   => auth()->id(),
            ]);

            return response()->json([
                'success'   => true,
                'message'   => 'Candidato aggiornato con successo.',
                'candidate' => $candidate->fresh()->load(['user', 'companies', 'media.media']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[CandidateController] Errore durante l\'aggiornamento del candidato.', [
                'public_id' => $id,
                'user_id'   => auth()->id(),
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => "Errore durante l'aggiornamento.",
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // =========================================================================
    // DELETE
    // =========================================================================
    public function delete(string $id)
    {
        $candidate = Candidate::where('public_id', $id)->first();

        if (!$candidate) {
            Log::warning('[CandidateController] Candidato non trovato in delete.', [
                'public_id' => $id,
                'user_id'   => auth()->id(),
            ]);
            return response()->json(['success' => false, 'message' => 'Candidato non trovato.'], 404);
        }

        if (!$candidate->active) {
            Log::warning('[CandidateController] Tentativo di disattivare un candidato già disattivato.', [
                'public_id' => $id,
                'user_id'   => auth()->id(),
            ]);
            return response()->json(['success' => false, 'message' => 'Candidato già disattivato.'], 409);
        }

        $candidate->update(['active' => 'false']);

        Log::info('[CandidateController] Candidato disattivato con successo.', [
            'public_id' => $id,
            'user_id'   => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Candidato disattivato con successo.',
        ]);
    }

    // =========================================================================
    // HELPERS PRIVATI
    // =========================================================================

    private function validateBilling(Request $request, string $billingType, bool $isSometimes = false): ?array
    {
        $req = $isSometimes ? 'sometimes' : 'required';

        $isCompanyForeign =
            $request->input('company.company_foreign') === 'true' ||
            $request->input('company_foreign') === 'true';

        $billingRules = match ($billingType) {
            'personal'   => [],
            'freelancer' => ['piva' => [$req, 'string', 'max:11']],
            'company'    => [
                'company_piva'          => [$req, 'string', 'max:11'],
                'company_social_reason' => [$req, 'string', 'max:255'],
                'company_mail'          => [$req, 'email', 'max:255'],
                'company_legal_address' => [$req, 'string', 'max:255'],
                'company_city'          => [$req, 'string', 'max:255'],
                'company_phone'         => [$req, 'string', 'max:50'],
                'company_province'      => [$req, 'string', 'max:255'],
                'company_zip'           => $isCompanyForeign
                    ? ['nullable', 'string', 'max:10']
                    : [$req, 'string', 'max:10'],
                'company_foreign'       => ['nullable', 'string', 'in:true,false'],
            ],
            default => [],
        };

        if (empty($billingRules)) {
            return null;
        }

        $v = Validator::make($request->all(), $billingRules);
        return $v->fails() ? $v->errors()->toArray() : null;
    }

    private function validateMedia(\Illuminate\Support\Collection $mediaItems): ?array
    {
        $types = $mediaItems->pluck('type');

        $missing = [];
        if (!$types->contains('fiscal_code')) $missing[] = 'fiscal_code';
        if (!$types->contains('id_document'))  $missing[] = 'id_document';

        if (!empty($missing)) {
            return ['media' => ['Tipi obbligatori mancanti: ' . implode(', ', $missing)]];
        }

        $duplicates = $types->duplicates();
        if ($duplicates->isNotEmpty()) {
            return ['media' => ['Tipi duplicati non ammessi: ' . $duplicates->unique()->implode(', ')]];
        }

        return null;
    }

    private function syncCompany(int $candidateId, string $billingType, Request $request, bool $update = false): void
    {
        $companyData = $request->input('company');
        $data        = is_array($companyData)
            ? $companyData
            : $request->only([
                'piva',
                'company_piva',
                'company_social_reason',
                'company_mail',
                'company_province',
                'company_legal_address',
                'company_city',
                'company_phone',
                'company_zip',
                'is_foreign_company',
                'company_foreign',
            ]);

        if (isset($data['company_foreign'])) {
            $data['is_foreign_company'] = $data['company_foreign'];
            unset($data['company_foreign']);
        }

        $data['billing_type'] = $billingType;

        if ($update) {
            CandidateCompany::updateOrCreate(['id_candidates' => $candidateId], $data);
        } else {
            CandidateCompany::create(array_merge(['id_candidates' => $candidateId], $data));
        }
    }

    private function syncMedia(int $candidateId, \Illuminate\Support\Collection $mediaItems): void
    {
        foreach ($mediaItems as $item) {
            CandidateMedia::create([
                'id_candidate' => $candidateId,
                'id_media'     => $item['id_media'],
                'type'         => $item['type'],
            ]);
        }
    }

    // =========================================================================
    // GET CANDIDATE EVENTS
    // =========================================================================
    public function getEvents(string $id): \Illuminate\Http\JsonResponse
    {
        try {
            // Recupera il candidato tramite public_id
            $candidate = Candidate::where('public_id', $id)->first();

            if (!$candidate) {
                Log::warning('[CandidateController] Candidato non trovato in getEvents.', [
                    'public_id' => $id,
                    'user_id'   => auth()->id(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Candidato non trovato.',
                ], 404);
            }

            // 🔒 Autorizzazione
            $this->authorize('view', $candidate);

            // Recupera gli esami pianificati del candidato con le relazioni
            $plannedExamsCandidates = \App\Models\PlannedExamCandidate::with([
                'plannedExam.exam',
            ])
                ->where('id_candidate', $candidate->id)
                ->orderBy('created_at', 'desc')
                ->get();

            if ($plannedExamsCandidates->isEmpty()) {
                Log::info('[CandidateController] Nessun evento trovato per il candidato.', [
                    'public_id'    => $id,
                    'candidate_id' => $candidate->id,
                    'user_id'      => auth()->id(),
                ]);
            }

            // Mapping della risposta
            $events = $plannedExamsCandidates->map(function ($pec) {
                $plannedExam = $pec->plannedExam;
                $exam        = $plannedExam?->exam;

                return [
                    // -- Dati iscrizione candidato all'esame --
                    'enrollment' => [
                        'public_id'  => $pec->public_id,
                        'created_at' => $pec->created_at,
                        'updated_at' => $pec->updated_at,
                    ],

                    // -- Dati esame pianificato --
                    'planned_exam' => $plannedExam ? [
                        'public_id'           => $plannedExam->public_id,
                        'date'                => $plannedExam->date,
                        'time'                => $plannedExam->time,
                        'end_time'            => $plannedExam->end_time,
                        'location'            => $plannedExam->location,
                        'created_at'          => $plannedExam->created_at,
                        'updated_at'          => $plannedExam->updated_at,
                        'active_exam_session' => $plannedExam->active_exam_session,
                    ] : null,

                    // -- Dati esame --
                    'exam' => $exam ? [
                        'public_id'   => $exam->public_id,
                        'name'        => $exam->name,
                        'type'        => $exam->type,
                        'description' => $exam->description,
                        'cost'        => $exam->cost,
                        'color'       => $exam->color,
                        'active'      => $exam->active,
                    ] : null,
                ];
            });



            return response()->json([
                'success' => true,
                'count'   => $events->count(),
                'events'  => $events,
            ], 200);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            Log::warning('[CandidateController] Accesso non autorizzato in getEvents.', [
                'public_id' => $id,
                'user_id'   => auth()->id(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Non autorizzato a visualizzare gli eventi di questo candidato.',
            ], 403);
        } catch (\Throwable $e) {
            Log::error('[CandidateController] Errore durante il recupero degli eventi del candidato.', [
                'public_id' => $id,
                'user_id'   => auth()->id(),
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Errore durante il recupero degli eventi del candidato.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}
