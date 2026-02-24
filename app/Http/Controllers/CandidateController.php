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
use Illuminate\Support\Facades\Validator;

class CandidateController extends Controller
{
    use AuthorizesRequests;
    private function decodificaCodiceFiscale($codiceFiscale): ?array
    {
        // Normalizza il codice fiscale (maiuscolo e rimuovi spazi)
        $codiceFiscale = strtoupper(trim($codiceFiscale));

        // Verifica lunghezza
        if (strlen($codiceFiscale) !== 16) {
            return null;
        }

        try {
            // Carica la lista dei comuni
            $comuniPath = base_path('database/comuni.json');

            if (!file_exists($comuniPath)) {
                throw new \Exception('File comuni.json non trovato');
            }

            $comuniJson = file_get_contents($comuniPath);
            $comuni = json_decode($comuniJson, true);

            if (!$comuni) {
                throw new \Exception('Errore nel parsing del file comuni.json');
            }

            // Estrai le parti del codice fiscale
            $cognome = substr($codiceFiscale, 0, 3);
            $nome = substr($codiceFiscale, 3, 3);
            $annoNascita = substr($codiceFiscale, 6, 2);
            $meseNascita = substr($codiceFiscale, 8, 1);
            $giornoSesso = substr($codiceFiscale, 9, 2);
            $codiceCatastale = substr($codiceFiscale, 11, 4);
            $carattereControllo = substr($codiceFiscale, 15, 1);

            // Decodifica il sesso e il giorno di nascita
            $giorno = intval($giornoSesso);
            $sesso = 'M'; // Maschio

            if ($giorno > 31) {
                $sesso = 'F'; // Femmina
                $giorno = $giorno - 40;
            }

            // Decodifica il mese
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
                'T' => '12'
            ];

            if (!isset($mesi[$meseNascita])) {
                return null;
            }

            $mese = $mesi[$meseNascita];

            // Determina l'anno completo (assumendo che gli anni 00-30 siano 2000-2030, e 31-99 siano 1931-1999)
            $annoCompleto = intval($annoNascita);
            if ($annoCompleto <= 30) {
                $annoCompleto += 2000;
            } else {
                $annoCompleto += 1900;
            }

            // Trova il comune dal codice catastale
            $comuneNascita = null;
            foreach ($comuni as $comune) {
                if ($comune['codiceCatastale'] === $codiceCatastale) {
                    $comuneNascita = $comune;
                    break;
                }
            }

            // Se non trova il comune nei comuni italiani, potrebbe essere un paese estero
            $luogoNascita = $comuneNascita ? $comuneNascita['nome'] : 'Paese estero (codice: ' . $codiceCatastale . ')';
            $provincia = $comuneNascita ? $comuneNascita['sigla'] : null;

            // Crea la data di nascita
            $dataNascita = sprintf('%04d-%02d-%02d', $annoCompleto, intval($mese), $giorno);

            // Verifica che la data sia valida
            if (!checkdate(intval($mese), $giorno, $annoCompleto)) {
                return null;
            }

            return [
                'codice_fiscale' => $codiceFiscale,
                'data_nascita' => $dataNascita,
                'sesso' => $sesso,
                'luogo_nascita' => $luogoNascita,
                'provincia_nascita' => $provincia,
                'anno_nascita' => $annoCompleto,
                'mese_nascita' => intval($mese),
                'giorno_nascita' => $giorno,
            ];
        } catch (\Exception $e) {
            \Log::error('Errore nella decodifica del codice fiscale: ' . $e->getMessage());
            return null;
        }
    }

    private function estraiDati()
    {
        $comuniPath = base_path('database/comuni.json');

        if (!file_exists($comuniPath)) {
            throw new \Exception('File comuni.json non trovato');
        }

        $comuniJson = file_get_contents($comuniPath);
        $comuni = json_decode($comuniJson, true);

        if (!$comuni || !is_array($comuni)) {
            return response()->json(['error' => 'Formato JSON non valido'], 400);
        }

        $result = [];

        foreach ($comuni as $comune) {
            if (
                isset($comune['popolazione'], $comune['sigla'], $comune['provincia']['nome']) &&
                $comune['popolazione'] > 10000
            ) {
                $sigla = $comune['sigla'];

                // Se la sigla non è ancora nel risultato, inizializzala
                if (!isset($result[$sigla])) {
                    $result[$sigla] = [
                        'provincia' => $sigla,
                        'citta' => []
                    ];
                }

                // Aggiunge la città con i suoi CAP
                $result[$sigla]['citta'][] = [
                    'nome' => $comune['nome'],
                    'cap' => isset($comune['cap']) ? $comune['cap'] : []
                ];
            }
        }

        return response()->json($result);
    }

    public function getAllData(): \Illuminate\Http\JsonResponse
    {
        try {
            $citta = $this->estraiDati();

            // Organizza i dati nella struttura richiesta
            $data = [
                'data' => [
                    'citta' => $citta,
                ]
            ];

            return response()->json($data, 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Errore nel recupero dei dati',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    // =========================================================================
    // INDEX - GET ALL CANDIDATES
    // =========================================================================
    public function index(): \Illuminate\Http\JsonResponse
    {
        try {
            $candidates = Candidate::with([
                'user',
                'companies',
                'media.media'
            ])->where('active', "true")->get();

            return response()->json([
                'success'    => true,
                'count'      => $candidates->count(),
                'candidates' => $candidates
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errore nel recupero dei candidati.',
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
    // =========================================================================
    // SHOW - GET SINGLE CANDIDATE BY ID
    // =========================================================================
    public function show(int $id): \Illuminate\Http\JsonResponse
    {
        try {
            // Carica relazioni aggiuntive
            $candidate = Candidate::with(['user','companies','media.media'])
                ->findOrFail($id);

            // 🔒 Controllo autorizzazione tramite Policy
            $this->authorize('view', $candidate);

            return response()->json([
                'success' => true,
                'candidate' => $candidate
            ], 200);

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            // Se l'utente non è autorizzato
            return response()->json([
                'success' => false,
                'message' => 'Non autorizzato a visualizzare questo candidato.'
            ], 403);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errore durante il recupero del candidato.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
    // =========================================================================
    // STORE
    // =========================================================================
    public function store(Request $request)
    {
        // ─── 1. Validazione base ──────────────────────────────────────────────
        $baseRules = [
            'name'               => ['required', 'string', 'max:255'],
            'surname'            => ['required', 'string', 'max:255'],
            'phone'              => ['required', 'string', 'max:50'],
            'fiscal_code'        => ['required', 'string', 'max:16'],
            'is_foreign'         => ['nullable', 'string'],
            'birthcountry'       => ['nullable', 'string', 'max:255'],
            'residence_address'  => ['required', 'string', 'max:255'],
            'residence_city'     => ['required', 'string', 'max:255'],
            'residence_province' => [
                'required_if:is_foreign,0',
                'nullable',
                'string',
                'max:10'
            ],
            'residence_zip' => [
                'required_if:is_foreign,0',
                'nullable',
                'string',
                'max:10'
            ],
            'residence_country'  => ['required', 'string', 'max:255'],
            'billing_type'       => ['required', 'string', 'in:personal,freelancer,company'],
            'media'              => ['required', 'array', 'min:2'],
            'media.*.id_media'   => ['required', 'integer', 'exists:media,id'],
            'media.*.type'       => ['required', 'string', 'in:fiscal_code,id_document,curriculum'],
        ];

        $validator = Validator::make($request->all(), $baseRules);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        // ─── 2. Billing dipendente ────────────────────────────────────────────
        $billingType  = $request->input('billing_type');
        $billingError = $this->validateBilling($request, $billingType);
        if ($billingError) {
            return response()->json(['success' => false, 'errors' => $billingError], 422);
        }

        // ─── 3. Media obbligatori ─────────────────────────────────────────────
        $mediaItems = collect($request->input('media', []));
        $mediaError = $this->validateMedia($mediaItems);
        if ($mediaError) {
            return response()->json(['success' => false, 'errors' => $mediaError], 422);
        }

        // ─── 4. Persistenza ───────────────────────────────────────────────────
        try {
            $decoded = $this->decodificaCodiceFiscale($request->input('fiscal_code'));

            if (!$decoded) {
                return response()->json([
                    'success' => false,
                    'errors'  => ['fiscal_code' => ['Codice fiscale non valido o non decodificabile']]
                ], 422);
            }

            DB::beginTransaction();

            $user = auth()->user();
            $candidate = Candidate::create([
                'id_user'            => $user->id,
                'name'               => $request->input('name'),
                'surname'            => $request->input('surname'),
                'email'              => $user->email,
                'phone'              => $request->input('phone'),
                'fiscal_code'        => $request->input('fiscal_code'),
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
                'active'             => "true",
            ]);

            $this->syncCompany($candidate->id, $billingType, $request);
            $this->syncMedia($candidate->id, $mediaItems);

            $user->candidate_registration_completed = "true";
            $user->save();

            DB::commit();

            return response()->json([
                'success'   => true,
                'message'   => 'Candidato creato con successo.',
                'candidate' => $candidate->load(['user', 'companies', 'media.media']),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
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
    public function update(Request $request, int $id)
    {
        $candidate = Candidate::find($id);
        if (!$candidate) {
            return response()->json(['success' => false, 'message' => 'Candidato non trovato.'], 404);
        }

        // ─── 1. Validazione base (tutti 'sometimes': aggiorna solo i campi inviati) ──
        $baseRules = [
            'name'               => ['sometimes', 'string', 'max:255'],
            'surname'            => ['sometimes', 'string', 'max:255'],
            'phone'              => ['sometimes', 'string', 'max:50'],
            'fiscal_code'        => ['sometimes', 'string', 'max:16'],
            'sex'                => ['sometimes', 'string', 'in:M,F,O'],
            'birthdate'          => ['sometimes', 'date'],
            'birthplace'         => ['sometimes', 'string', 'max:255'],
            'birthprovince'      => ['sometimes', 'string', 'max:10'],
            'birthcommun'        => ['sometimes', 'string', 'max:255'],
            'is_foreign'         => ['nullable', 'boolean'],
            'birthcountry'       => ['nullable', 'string', 'max:255'],
            'residence_address'  => ['sometimes', 'string', 'max:255'],
            'residence_city'     => ['sometimes', 'string', 'max:255'],
            'residence_province' => ['sometimes', 'string', 'max:10'],
            'residence_zip'      => ['sometimes', 'string', 'max:10'],
            'residence_country'  => ['sometimes', 'string', 'max:255'],
            'billing_type'       => ['sometimes', 'string', 'in:personal,freelancer,company'],
            // Media opzionale in update: se inviato deve essere completo e valido
            'media'              => ['sometimes', 'array', 'min:2'],
            'media.*.id_media'   => ['required_with:media', 'integer', 'exists:media,id'],
            'media.*.type'       => ['required_with:media', 'string', 'in:fiscal_code,id_document,curriculum'],
        ];

        $validator = Validator::make($request->all(), $baseRules);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        // ─── 2. Billing (solo se almeno un campo billing è presente) ─────────
        $billingFields = [
            'billing_type', 'piva', 'company_piva', 'company_social_reason',
            'company_mail', 'company_province', 'company_legal_address',
            'company_city', 'company_phone',
        ];
        $hasBillingData = $request->hasAny($billingFields);

        if ($hasBillingData) {
            // Usa il nuovo billing_type se inviato, altrimenti quello già salvato
            $billingType  = $request->input('billing_type')
                ?? $candidate->companies()->latest()->value('billing_type')
                ?? 'personal';
            $billingError = $this->validateBilling($request, $billingType, isSometimes: true);
            if ($billingError) {
                return response()->json(['success' => false, 'errors' => $billingError], 422);
            }
        }

        // ─── 3. Media (solo se presente nel body) ─────────────────────────────
        if ($request->has('media')) {
            $mediaItems = collect($request->input('media'));
            $mediaError = $this->validateMedia($mediaItems);
            if ($mediaError) {
                return response()->json(['success' => false, 'errors' => $mediaError], 422);
            }
        }

        // ─── 4. Persistenza ───────────────────────────────────────────────────
        try {
            DB::beginTransaction();

            // Aggiorna solo i campi presenti nel body
            $candidate->fill(
                $request->only([
                    'name', 'surname', 'phone', 'fiscal_code', 'sex',
                    'birthdate', 'birthplace', 'birthprovince', 'birthcommun',
                    'is_foreign', 'birthcountry',
                    'residence_address', 'residence_city', 'residence_province',
                    'residence_zip', 'residence_country',
                ])
            );
            $candidate->save();

            // Aggiorna billing solo se dati billing presenti
            if ($hasBillingData) {
                $this->syncCompany($candidate->id, $billingType, $request, update: true);
            }

            // Sostituisce tutti i media solo se l'array è stato inviato
            if ($request->has('media')) {
                $candidate->media()->delete();
                $this->syncMedia($candidate->id, collect($request->input('media')));
            }

            DB::commit();

            return response()->json([
                'success'   => true,
                'message'   => 'Candidato aggiornato con successo.',
                'candidate' => $candidate->fresh()->load(['user', 'companies', 'media.media']),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => "Errore durante l'aggiornamento del candidato.",
                'error'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // =========================================================================
    // DELETE (soft: active = false)
    // =========================================================================
    public function delete(int $id)
    {
        $candidate = Candidate::find($id);
        if (!$candidate) {
            return response()->json(['success' => false, 'message' => 'Candidato non trovato.'], 404);
        }

        if (!$candidate->active) {
            return response()->json(['success' => false, 'message' => 'Candidato già disattivato.'], 409);
        }

        $candidate->update(['active' => "false"]);

        return response()->json([
            'success' => true,
            'message' => 'Candidato disattivato con successo.',
        ]);
    }

    // =========================================================================
    // HELPER PRIVATI
    // =========================================================================

    /**
     * Valida i campi billing in base al tipo.
     * Con $isSometimes=true usa 'sometimes' invece di 'required' (update parziale).
     */
    private function validateBilling(Request $request, string $billingType, bool $isSometimes = false): ?array
    {
        $req = $isSometimes ? 'sometimes' : 'required';

        $billingRules = match ($billingType) {
            'personal'   => [],
            'freelancer' => [
                'piva' => [$req, 'string', 'max:11'],
            ],
            'company'    => [
                'company_piva'          => [$req, 'string', 'max:11'],
                'company_social_reason' => [$req, 'string', 'max:255'],
                'company_mail'          => [$req, 'email', 'max:255'],
                'company_province'      => [$req, 'string', 'max:10'],
                'company_legal_address' => [$req, 'string', 'max:255'],
                'company_city'          => [$req, 'string', 'max:255'],
                'company_phone'         => [$req, 'string', 'max:50'],
            ],
            default => [],
        };

        if (empty($billingRules)) {
            return null;
        }

        $v = Validator::make($request->all(), $billingRules);
        return $v->fails() ? $v->errors()->toArray() : null;
    }

    /**
     * Verifica che fiscal_code e id_document siano presenti e senza duplicati.
     */
    private function validateMedia(\Illuminate\Support\Collection $mediaItems): ?array
    {
        $types = $mediaItems->pluck('type');

        $missing = [];
        if (!$types->contains('fiscal_code')) $missing[] = 'fiscal_code';
        if (!$types->contains('id_document')) $missing[] = 'id_document';

        if (!empty($missing)) {
            return ['media' => ['Tipi obbligatori mancanti: ' . implode(', ', $missing)]];
        }

        $duplicates = $types->duplicates();
        if ($duplicates->isNotEmpty()) {
            return ['media' => ['Tipi duplicati non ammessi: ' . $duplicates->unique()->implode(', ')]];
        }

        return null;
    }

    /**
     * Crea o aggiorna il record CandidateCompany.
     */
    private function syncCompany(int $candidateId, string $billingType, Request $request, bool $update = false): void
    {
        $data = ['billing_type' => $billingType];

        if ($billingType === 'freelancer') {
            if ($request->has('piva')) $data['piva'] = $request->input('piva');
        } elseif ($billingType === 'company') {
            foreach ([
                         'company_piva', 'company_social_reason', 'company_mail',
                         'company_province', 'company_legal_address', 'company_city', 'company_phone',
                     ] as $field) {
                if ($request->has($field)) $data[$field] = $request->input($field);
            }
        }

        if ($update) {
            CandidateCompany::updateOrCreate(['id_candidates' => $candidateId], $data);
        } else {
            CandidateCompany::create(array_merge(['id_candidates' => $candidateId], $data));
        }
    }

    /**
     * Inserisce i record CandidateMedia per un candidato.
     */
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
}
