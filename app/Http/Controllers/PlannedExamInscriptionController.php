<?php

namespace App\Http\Controllers;

use App\Mail\CandidateInscriptionMail;
use App\Models\PlannedExam;
use App\Models\PlannedExamInscription;
use App\Models\PlannedExamCandidate;
use App\Services\UserService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App\Services\MediaService;

class PlannedExamInscriptionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected MediaService $mediaService,
        protected UserService $userService,
    ) {}

    /**
     * CREA RICHIESTA
     */
    public function store(Request $request)
    {
        $request->validate([
            'id_planned_exam' => 'required|exists:planned_exams,public_id',
            'id_candidate'    => 'required|exists:candidates,public_id',
            'id_gdpr'         => 'required|exists:GDPR_versions,public_id',
        ]);

        $plannedExam = PlannedExam::where('public_id', $request->id_planned_exam)->firstOrFail();
        $candidate   = \App\Models\Candidate::where('public_id', $request->id_candidate)->firstOrFail();

        // Controllo data (non oggi o passato)
        if (Carbon::parse($plannedExam->date)->startOfDay() <= Carbon::today()) {
            return response()->json([
                'message' => 'Non puoi iscriverti a un esame con data odierna o passata'
            ], 422);
        }

        // Controllo iscrizione alla stessa tipologia esame
        $alreadySubscribed = PlannedExamInscription::where('id_candidate', $candidate->id)
            ->whereHas('plannedExam', function ($query) use ($plannedExam) {
                $query->where('id_exam', $plannedExam->id_exam);
            })
            ->whereNotIn('status', ['retired', 'revoked'])
            ->exists();

        if ($alreadySubscribed) {
            return response()->json([
                'message' => 'Sei già iscritto a questa tipologia di esame'
            ], 422);
        }

        // Controllo duplicato esatto sulla stessa sessione
        $exists = PlannedExamInscription::where([
            'id_planned_exam' => $plannedExam->id,
            'id_candidate'    => $candidate->id,
        ])
            ->whereNotIn('status', ['retired', 'revoked'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Richiesta già esistente'], 422);
        }

        $gdprVersion = \App\Models\GDPRVersion::where('public_id', $request->id_gdpr)->firstOrFail();

        // Creazione iscrizione + firma GDPR in transazione atomica
        $inscription = DB::transaction(function () use ($plannedExam, $candidate, $gdprVersion) {
            $inscription = PlannedExamInscription::create([
                'id_planned_exam' => $plannedExam->id,
                'id_candidate'    => $candidate->id,
                'status'          => 'sended',
            ]);

            \App\Models\GDPRSigned::create([
                'id_gdpr_version'   => $gdprVersion->id,
                'id_candidate'      => $candidate->id,
                'id_user'           => auth()->id(),
                'accepted_at'       => now(),
                'accepted'          => 'true',
                'date'              => now()->toDateString(),
            ]);

            return $inscription;
        });

        $this->sendCandidateMail($inscription);
        $this->sendAdminMail($inscription);

        return response()->json($inscription, 201);
    }

    /**
     * TUTTE LE RICHIESTE
     */
    public function index()
    {
        $inscriptions = PlannedExamInscription::with([
            'plannedExam.exam',
            'plannedExam.testCenter',
            'candidate'
        ])->get()
            ->map(fn($i) => [
                'public_id'        => $i->public_id,
                'status'           => $i->status,
                'created_at'       => $i->created_at,
                'note'             => $i->note,
                'document'         => $i->document,
                'invoice'          => $i->invoice,
                'unsigned_document' => $i->unsigned_document,
                'unsigned_invoice'  => $i->unsigned_invoice,
                'planned_exam'     => [
                    'public_id'   => $i->plannedExam->public_id,
                    'date'        => $i->plannedExam->date,
                    'time'        => $i->plannedExam->time,
                    'end_time'    => $i->plannedExam->end_time,
                    'location'    => $i->plannedExam->location,
                    'status'      => $i->plannedExam->status,
                    'exam'        => [
                        'public_id'   => $i->plannedExam->exam->public_id,
                        'name'        => $i->plannedExam->exam->name,
                        'type'        => $i->plannedExam->exam->type,
                        'description' => $i->plannedExam->exam->description,
                        'cost'        => $i->plannedExam->exam->cost,
                        'color'       => $i->plannedExam->exam->color,
                        'active'      => $i->plannedExam->exam->active,
                        'created_at'  => $i->plannedExam->exam->created_at,
                        'updated_at'  => $i->plannedExam->exam->updated_at,
                    ],
                    'test_center' => [
                        'public_id'   => $i->plannedExam->testCenter->public_id,
                        'name'        => $i->plannedExam->testCenter->name,
                        'description' => $i->plannedExam->testCenter->description,
                        'address'     => $i->plannedExam->testCenter->address,
                        'city'        => $i->plannedExam->testCenter->city,
                        'province'    => $i->plannedExam->testCenter->province,
                    ],
                ],
                'candidate' => [
                    'public_id' => $i->candidate->public_id,
                    'name'      => $i->candidate->name,
                    'surname'   => $i->candidate->surname,
                    'email'     => $i->candidate->email,
                ],
            ]);

        return response()->json($inscriptions);
    }

    /**
     * ISCRIZIONI PER CANDIDATO
     */
    public function byCandidate(Request $request, string $publicId)
    {
        $candidate = \App\Models\Candidate::where('public_id', $publicId)->firstOrFail();

        // Lo user può vedere solo le proprie iscrizioni, ma l'admin e superAdmin possono vedere tutte le iscrizioni di tutti i candidati
        $authUser = $request->user();
        $authUser->loadMissing('role');
        if ($authUser->role->name === 'user' && $candidate->id_user !== $authUser->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $inscriptions = PlannedExamInscription::with([
            'plannedExam.exam',
            'plannedExam.testCenter',
        ])
            ->where('id_candidate', $candidate->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($i) => [
                'public_id'        => $i->public_id,
                'status'           => $i->status,
                'created_at'       => $i->created_at,
                'note'             => $i->note,
                'document'         => $i->document,
                'invoice'          => $i->invoice,
                'unsigned_document' => $i->unsigned_document,
                'unsigned_invoice' => $i->unsigned_invoice,
                'planned_exam'     => [
                    'public_id'   => $i->plannedExam->public_id,
                    'date'        => $i->plannedExam->date,
                    'time'        => $i->plannedExam->time,
                    'end_time'    => $i->plannedExam->end_time,
                    'location'    => $i->plannedExam->location,
                    'status'      => $i->plannedExam->status,
                    'exam'        => [
                        'public_id'   => $i->plannedExam->exam->public_id,
                        'name'        => $i->plannedExam->exam->name,
                        'type'        => $i->plannedExam->exam->type,
                        'description' => $i->plannedExam->exam->description,
                        'cost'        => $i->plannedExam->exam->cost,
                        'color'       => $i->plannedExam->exam->color,
                        'active'      => $i->plannedExam->exam->active,
                        'created_at'  => $i->plannedExam->exam->created_at,
                        'updated_at'  => $i->plannedExam->exam->updated_at,
                    ],
                    'test_center' => [
                        'public_id'   => $i->plannedExam->testCenter->public_id,
                        'name'        => $i->plannedExam->testCenter->name,
                        'description' => $i->plannedExam->testCenter->description,
                        'address'     => $i->plannedExam->testCenter->address,
                        'city'        => $i->plannedExam->testCenter->city,
                        'province'    => $i->plannedExam->testCenter->province,
                    ],
                ],
                'candidate' => [
                    'public_id' => $candidate->public_id,
                    'name'      => $candidate->name,
                    'surname'   => $candidate->surname,
                    'email'     => $candidate->email,
                ],
            ]);

        return response()->json($inscriptions);
    }

    /**
     * FILTRO PER STATUS
     */
    public function byStatus(string $status)
    {
        return PlannedExamInscription::with(['plannedExam', 'candidate', 'plannedExam.exam'])
            ->where('status', $status)
            ->get();
    }

    /**
     * DETTAGLIO
     */
    public function show(string $publicId)
    {
        $inscription = PlannedExamInscription::with([
            'plannedExam.exam',
            'plannedExam.testCenter',
            'candidate.media',
            'candidate.media.media',
            'documentMedia',
            'invoiceMedia',
            'unsignedDocumentMedia',
            'unsignedInvoiceMedia',
        ])->where('public_id', $publicId)->firstOrFail();

        $this->authorize('view', $inscription);

        $inscriptionMapped = [
            'public_id'         => $inscription->public_id,
            'status'            => $inscription->status,
            'created_at'        => $inscription->created_at,
            'note'              => $inscription->note,
            'document'          => $inscription->document,
            'invoice'           => $inscription->invoice,
            'unsigned_document' => $inscription->unsigned_document,
            'unsigned_invoice'  => $inscription->unsigned_invoice,
            'planned_exam'      => [
                'public_id'   => $inscription->plannedExam->public_id,
                'date'        => $inscription->plannedExam->date,
                'time'        => $inscription->plannedExam->time,
                'end_time'    => $inscription->plannedExam->end_time,
                'location'    => $inscription->plannedExam->location,
                'status'      => $inscription->plannedExam->status,
                'exam'        => [
                    'public_id'   => $inscription->plannedExam->exam->public_id,
                    'name'        => $inscription->plannedExam->exam->name,
                    'type'        => $inscription->plannedExam->exam->type,
                    'description' => $inscription->plannedExam->exam->description,
                    'cost'        => $inscription->plannedExam->exam->cost,
                    'color'       => $inscription->plannedExam->exam->color,
                    'active'      => $inscription->plannedExam->exam->active,
                    'created_at'  => $inscription->plannedExam->exam->created_at,
                    'updated_at'  => $inscription->plannedExam->exam->updated_at,
                ],
                'test_center' => [
                    'public_id'   => $inscription->plannedExam->testCenter->public_id,
                    'name'        => $inscription->plannedExam->testCenter->name,
                    'description' => $inscription->plannedExam->testCenter->description,
                    'address'     => $inscription->plannedExam->testCenter->address,
                    'city'        => $inscription->plannedExam->testCenter->city,
                    'province'    => $inscription->plannedExam->testCenter->province,
                ],
            ],
            'candidate' => [
                'public_id'         => $inscription->candidate->public_id,
                'name'              => $inscription->candidate->name,
                'surname'           => $inscription->candidate->surname,
                'fiscal_code'           => $inscription->candidate->fiscal_code,
                'email'             => $inscription->candidate->email,
                'active'            => $inscription->candidate->active,
                'birthcommun'       => $inscription->candidate->birthcommun,
                'birthcountry'      => $inscription->candidate->birthcountry,
                'birthdate'         => $inscription->candidate->birthdate,
                'birthplace'        => $inscription->candidate->birthplace,
                'birthprovince'     => $inscription->candidate->birthprovince,
                'phone'             => $inscription->candidate->phone,
                'residence_address' => $inscription->candidate->residence_address,
                'residence_city'    => $inscription->candidate->residence_city,
                'residence_country' => $inscription->candidate->residence_country,
                'residence_province' => $inscription->candidate->residence_province,
                'residence_zip'     => $inscription->candidate->residence_zip,
                'sex'               => $inscription->candidate->sex,
                'media'             => $inscription->candidate->media,
                'is_foreign'             => $inscription->candidate->is_foreign,

            ],
            'document_media'          => $inscription->documentMedia,
            'invoice_media'           => $inscription->invoiceMedia,
            'unsigned_document_media' => $inscription->unsignedDocumentMedia,
            'unsigned_invoice_media'  => $inscription->unsignedInvoiceMedia,
        ];

        return response()->json($inscriptionMapped);
    }

    /**
     * CAMBIO STATUS
     */
    public function updateStatus(Request $request, string $publicId)
    {
        $request->validate([
            'status'            => 'required|in:revoked,sended,waiting_payment,sended_payment,approved,retired',
            'note'              => 'nullable|string|max:2000',
            'unsigned_document' => 'nullable|integer|exists:media,id',
            'unsigned_invoice'  => 'nullable|integer|exists:media,id',
            'document'          => 'nullable|integer|exists:media,id',
            'invoice'           => 'nullable|integer|exists:media,id',
            'force'             => 'nullable|boolean',
        ]);

        $inscription = PlannedExamInscription::with('candidate')
            ->where('public_id', $publicId)
            ->firstOrFail();

        // ── Autorizzazione ────────────────────────────────────────────────────────
        $user = $request->user();
        $user->loadMissing('role');
        $isAdmin = in_array($user->role->name, ['admin', 'superAdmin']);

        if (!$isAdmin) {
            $this->authorize('view', $inscription->candidate);

            $allowedForUser = ['retired', 'sended_payment'];
            if (!in_array($request->status, $allowedForUser, true)) {
                return response()->json([
                    'error' => 'Non sei autorizzato a impostare questo stato.',
                ], 403);
            }
        }
        // ── Fine autorizzazione ───────────────────────────────────────────────────

        DB::beginTransaction();

        try {
            $isForced = $isAdmin && (bool) $request->input('force', false);

            if ($inscription->status === 'retired') {
                throw new \Exception('Impossibile modificare un\'iscrizione ritirata.');
            }

            if ($request->status === 'sended') {
                $this->clearInscriptionMedia($inscription);
            }

            if (in_array($request->status, ['approved', 'retired', 'revoked'])) {
                $this->clearUnsignedMedia($inscription);
            }

            if (!$isForced) {
                $this->validateStatusTransition($inscription->status, $request->status);
            }

            if ($request->status === 'waiting_payment') {
                if (!$isForced && (empty($request->unsigned_document) || empty($request->unsigned_invoice))) {
                    throw new \Exception(
                        'Per passare a "In attesa di pagamento" è obbligatorio allegare unsigned_document e unsigned_invoice.'
                    );
                }
                if (!empty($request->unsigned_document)) {
                    $inscription->unsigned_document = $request->unsigned_document;
                }
                if (!empty($request->unsigned_invoice)) {
                    $inscription->unsigned_invoice = $request->unsigned_invoice;
                }
            }

            if ($request->status === 'sended_payment') {
                if (!$isForced && (empty($request->document) || empty($request->invoice))) {
                    throw new \Exception(
                        'Per confermare il pagamento è obbligatorio allegare document e invoice firmati.'
                    );
                }
                if (!empty($request->document)) {
                    $inscription->document = $request->document;
                }
                if (!empty($request->invoice)) {
                    $inscription->invoice = $request->invoice;
                }
            }

            if ($request->status === 'revoked') {
                if (empty($request->note)) {
                    throw new \Exception('La motivazione (note) è obbligatoria per revocare un\'iscrizione.');
                }
                $inscription->note = $request->note;
            }

            $previousStatus    = $inscription->status;
            $inscription->status = $request->status;

            if ($request->status === 'approved') {
                PlannedExamCandidate::firstOrCreate([
                    'id_candidate'    => $inscription->id_candidate,
                    'id_planned_exam' => $inscription->id_planned_exam,
                ]);
            }

            if (in_array($request->status, ['retired', 'revoked']) && $previousStatus === 'approved') {
                PlannedExamCandidate::where([
                    'id_candidate'    => $inscription->id_candidate,
                    'id_planned_exam' => $inscription->id_planned_exam,
                ])->delete();
            }

            $inscription->save();

            $this->sendCandidateMail($inscription, $isForced);

            if ($request->status === 'sended_payment') {
                $this->sendAdminMail($inscription, $isForced);
            }

            DB::commit();

            $inscription = $inscription->fresh([
                'plannedExam',
                'plannedExam.exam',
                'plannedExam.testCenter',
                'candidate',
                'candidate.media',
                'documentMedia',
                'invoiceMedia',
                'unsignedDocumentMedia',
                'unsignedInvoiceMedia',
            ]);

            return response()->json([
                'public_id'         => $inscription->public_id,
                'status'            => $inscription->status,
                'created_at'        => $inscription->created_at,
                'note'              => $inscription->note,
                'document'          => $inscription->document,
                'invoice'           => $inscription->invoice,
                'unsigned_document' => $inscription->unsigned_document,
                'unsigned_invoice'  => $inscription->unsigned_invoice,
                'planned_exam'      => [
                    'public_id'   => $inscription->plannedExam->public_id,
                    'date'        => $inscription->plannedExam->date,
                    'time'        => $inscription->plannedExam->time,
                    'end_time'    => $inscription->plannedExam->end_time,
                    'location'    => $inscription->plannedExam->location,
                    'status'      => $inscription->plannedExam->status,
                    'exam'        => [
                        'public_id'   => $inscription->plannedExam->exam->public_id,
                        'name'        => $inscription->plannedExam->exam->name,
                        'type'        => $inscription->plannedExam->exam->type,
                        'description' => $inscription->plannedExam->exam->description,
                        'cost'        => $inscription->plannedExam->exam->cost,
                        'color'       => $inscription->plannedExam->exam->color,
                        'active'      => $inscription->plannedExam->exam->active,
                        'created_at'  => $inscription->plannedExam->exam->created_at,
                        'updated_at'  => $inscription->plannedExam->exam->updated_at,
                    ],
                    'test_center' => [
                        'public_id'   => $inscription->plannedExam->testCenter->public_id,
                        'name'        => $inscription->plannedExam->testCenter->name,
                        'description' => $inscription->plannedExam->testCenter->description,
                        'address'     => $inscription->plannedExam->testCenter->address,
                        'city'        => $inscription->plannedExam->testCenter->city,
                        'province'    => $inscription->plannedExam->testCenter->province,
                    ],
                ],
                'candidate' => [
                    'public_id'  => $inscription->candidate->public_id,
                    'name'       => $inscription->candidate->name,
                    'surname'    => $inscription->candidate->surname,
                    'email'      => $inscription->candidate->email,
                    'active'     => $inscription->candidate->active,
                    'birthcommun' => $inscription->candidate->birthcommun,
                    'birthcountry' => $inscription->candidate->birthcountry,
                    'birthdate'  => $inscription->candidate->birthdate,
                    'birthplace' => $inscription->candidate->birthplace,
                    'birthprovince' => $inscription->candidate->birthprovince,
                    'phone'      => $inscription->candidate->phone,
                    'residence_address' => $inscription->candidate->residence_address,
                    'residence_city' => $inscription->candidate->residence_city,
                    'residence_country' => $inscription->candidate->residence_country,
                    'residence_province' => $inscription->candidate->residence_province,
                    'residence_zip' => $inscription->candidate->residence_zip,
                    'sex'        => $inscription->candidate->sex,
                    'media'      => $inscription->candidate->media, // torna esattamente come dalla relazione
                ],
                'document_media'          => $inscription->documentMedia,
                'invoice_media'           => $inscription->invoiceMedia,
                'unsigned_document_media' => $inscription->unsignedDocumentMedia,
                'unsigned_invoice_media'  => $inscription->unsignedInvoiceMedia,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * ELIMINA TUTTI I MEDIA COLLEGATI ALL'ISCRIZIONE
     */
    private function clearInscriptionMedia(PlannedExamInscription $inscription): void
    {
        foreach (['document', 'invoice', 'unsigned_document', 'unsigned_invoice'] as $field) {
            $mediaId = $inscription->{$field};
            if ($mediaId) {
                $this->mediaService->deleteMedia((int) $mediaId);
                $inscription->{$field} = null;
            }
        }
    }

    /**
     * ELIMINA SOLO I TEMPLATE NON FIRMATI
     */
    private function clearUnsignedMedia(PlannedExamInscription $inscription): void
    {
        foreach (['unsigned_document', 'unsigned_invoice'] as $field) {
            $mediaId = $inscription->{$field};
            if ($mediaId) {
                $this->mediaService->deleteMedia((int) $mediaId);
                $inscription->{$field} = null;
            }
        }
    }

    /**
     * VALIDAZIONE FLUSSO STATUS (STATE MACHINE)
     */
    private function validateStatusTransition(string $current, string $new): void
    {
        $allowed = [
            'sended'          => ['waiting_payment', 'revoked', 'retired'],
            'waiting_payment' => ['sended_payment',  'revoked', 'retired'],
            'sended_payment'  => ['approved',         'revoked', 'retired'],
            'approved'        => ['retired', 'revoked'],
            'revoked'         => [],
            'retired'         => [],
        ];

        if (!in_array($new, $allowed[$current] ?? [], true)) {
            throw new \Exception("Transizione stato non valida: $current → $new");
        }
    }

    /**
     * MAIL AL CANDIDATO
     */
    private function sendCandidateMail(PlannedExamInscription $inscription, bool $isOverride = false): void
    {
        $payload = $this->getCandidateMailPayload($inscription->status, $isOverride);

        Mail::to($inscription->candidate->email)
            ->send(new CandidateInscriptionMail($inscription, $payload, 'candidate'));
    }

    /**
     * MAIL AGLI ADMIN
     */
    private function sendAdminMail(PlannedExamInscription $inscription, bool $isOverride = false): void
    {
        $admins = $this->userService->getAdminUsersByNotificationType('inscriptions');

        if ($admins->isEmpty()) return;

        $payload = $this->getAdminMailPayload($inscription->status, $isOverride);

        foreach ($admins as $admin) {
            Mail::to($admin->email)
                ->send(new CandidateInscriptionMail($inscription, $payload, 'admin'));
        }
    }

    /**
     * CONTENUTO MAIL CANDIDATO PER OGNI STATO
     */
    private function getCandidateMailPayload(string $status, bool $isOverride = false): array
    {
        if ($isOverride) {
            return match ($status) {
                'sended' => [
                    'subject' => 'La tua iscrizione è stata reimpostata',
                    'title'   => 'Iscrizione reimpostata dall\'amministratore',
                    'body'    => 'La tua iscrizione è stata reimpostata allo stato iniziale da un amministratore. I documenti precedentemente caricati sono stati rimossi. Puoi accedere alla piattaforma per verificare la situazione aggiornata.',
                ],
                'waiting_payment' => [
                    'subject' => 'Documenti aggiornati – attesa pagamento',
                    'title'   => 'Iscrizione reimpostata in attesa di pagamento',
                    'body'    => 'La tua iscrizione è stata reimpostata in attesa di pagamento da un amministratore. I documenti aggiornati sono disponibili nella tua area riservata. Scaricali, firmali e carica i file richiesti per procedere.',
                ],
                'sended_payment' => [
                    'subject' => 'Pagamento reimpostato dall\'amministratore',
                    'title'   => 'Iscrizione reimpostata a pagamento inviato',
                    'body'    => 'La tua iscrizione è stata reimpostata allo stato "pagamento inviato" da un amministratore. Puoi accedere alla piattaforma per ulteriori informazioni.',
                ],
                'revoked' => [
                    'subject' => 'La tua iscrizione è stata revocata',
                    'title'   => 'Iscrizione revocata dall\'amministratore',
                    'body'    => 'La tua iscrizione è stata revocata da un amministratore. Consulta la piattaforma per leggere le motivazioni. Se hai dubbi, contatta il nostro supporto.',
                ],
                default => [
                    'subject' => 'Aggiornamento sulla tua iscrizione',
                    'title'   => 'Stato iscrizione aggiornato',
                    'body'    => 'Lo stato della tua iscrizione è stato aggiornato da un amministratore. Accedi alla piattaforma per i dettagli.',
                ],
            };
        }

        return match ($status) {
            'sended' => [
                'subject' => 'Richiesta di iscrizione inviata',
                'title'   => 'Abbiamo ricevuto la tua richiesta',
                'body'    => 'La tua richiesta di iscrizione è stata inviata correttamente. Il nostro staff la verificherà a breve e riceverai un aggiornamento via e-mail non appena ci saranno novità.',
            ],
            'waiting_payment' => [
                'subject' => 'Azione richiesta – documenti da firmare',
                'title'   => 'La tua iscrizione è in attesa di pagamento',
                'body'    => 'La tua richiesta di iscrizione è stata validata. Ti confermiamo che sei in possesso dei requisiti per poter accedere all\'esame. Per completare la procedura, accedi alla tua area riservata, scarica i documenti allegati, firmali e caricali insieme alla ricevuta di pagamento.',
            ],
            'sended_payment' => [
                'subject' => 'Documenti ricevuti – pagamento in verifica',
                'title'   => 'Stiamo verificando il tuo pagamento',
                'body'    => 'Abbiamo ricevuto i documenti firmati e la ricevuta di pagamento. Il nostro staff provvederà alla verifica nel minor tempo possibile. Riceverai una conferma via e-mail al termine del controllo.',
            ],
            'approved' => [
                'subject' => 'Iscrizione approvata!',
                'title'   => 'La tua iscrizione è stata approvata',
                'body'    => 'Congratulazioni! La tua iscrizione è stata approvata ufficialmente. Accedi alla piattaforma per consultare tutti i dettagli relativi all\'esame.',
            ],
            'revoked' => [
                'subject' => 'La tua iscrizione è stata revocata',
                'title'   => 'Iscrizione revocata',
                'body'    => 'La tua iscrizione è stata revocata. Puoi consultare la motivazione nella tua area riservata. Per qualsiasi chiarimento non esitare a contattarci.',
            ],
            'retired' => [
                'subject' => 'Hai ritirato la tua iscrizione',
                'title'   => 'Iscrizione ritirata',
                'body'    => 'La tua richiesta di iscrizione è stata ritirata con successo. Se cambierai idea potrai presentare una nuova richiesta in qualsiasi momento.',
            ],
            default => [
                'subject' => 'Aggiornamento sulla tua iscrizione',
                'title'   => 'Stato iscrizione aggiornato',
                'body'    => 'Lo stato della tua iscrizione è stato aggiornato. Accedi alla piattaforma per i dettagli.',
            ],
        };
    }

    /**
     * CONTENUTO MAIL ADMIN PER OGNI STATO
     */
    private function getAdminMailPayload(string $status, bool $isOverride = false): array
    {
        return match ($status) {
            'sended' => [
                'subject' => 'Nuova richiesta di iscrizione ricevuta',
                'title'   => 'È arrivata una nuova iscrizione',
                'body'    => 'Un candidato ha inviato una nuova richiesta di iscrizione. Accedi alla piattaforma per visualizzare i dettagli e procedere con la verifica.',
            ],
            'sended_payment' => [
                'subject' => 'Pagamento inviato da un candidato',
                'title'   => 'Documenti firmati e pagamento ricevuti',
                'body'    => 'Un candidato ha caricato i documenti firmati e la ricevuta di pagamento. Accedi alla piattaforma per verificare i file e procedere con l\'approvazione.',
            ],
            default => [
                'subject' => 'Aggiornamento iscrizione candidato',
                'title'   => 'Stato iscrizione aggiornato',
                'body'    => 'Lo stato di un\'iscrizione è stato aggiornato. Accedi alla piattaforma per i dettagli.',
            ],
        };
    }
}
