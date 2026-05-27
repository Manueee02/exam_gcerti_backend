<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\GDPR;
use App\Models\GDPRVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mews\Purifier\Facades\Purifier;

class GDPRController extends Controller
{
    /**
     * GET /gdpr
     * Lista GDPR con versione attiva
     */
    public function index(Request $request)
    {
        $query = GDPR::with(['exam', 'activeVersion']);

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('id_exam')) {
            $exam = Exam::where('public_id', $request->id_exam)->first();
            $query->where('id_exam', $exam?->id);
        }

        $gdprs = $query->orderBy('created_at', 'desc')->get();

        return response()->json($gdprs->map(fn($g) => $this->mapGdpr($g)));
    }

    /**
     * GET /gdpr/{public_id}
     * Dettaglio GDPR con storico versioni
     */
    public function show(string $publicId)
    {
        $gdpr = GDPR::with([
            'exam',
            'versions' => fn($q) => $q->orderBy('version', 'desc'),
        ])->where('public_id', $publicId)->firstOrFail();

        return response()->json($this->mapGdprWithVersions($gdpr));
    }

    /**
     * GET /gdpr/active?type=inscription&id_exam={public_id}
     * Versione attiva per type + esame, con fallback globale
     */
    public function active(Request $request)
    {
        $request->validate([
            'type'    => 'required|in:inscription,exam',
            'id_exam' => 'nullable|string',
        ]);

        $examId = null;
        if ($request->filled('id_exam')) {
            $examId = Exam::where('public_id', $request->id_exam)->value('id');
        }

        $gdpr = null;
        if ($examId) {
            $gdpr = GDPR::where('type', $request->type)
                ->where('id_exam', $examId)
                ->whereHas('activeVersion')
                ->with(['exam', 'activeVersion'])
                ->first();
        }

        if (!$gdpr) {
            $gdpr = GDPR::where('type', $request->type)
                ->whereNull('id_exam')
                ->whereHas('activeVersion')
                ->with(['exam', 'activeVersion'])
                ->first();
        }

        if (!$gdpr?->activeVersion) {
            return response()->json(['message' => 'Nessun GDPR attivo trovato'], 404);
        }

        return response()->json([
            'gdpr_public_id' => $gdpr->public_id,
            'title'          => $gdpr->title,
            'type'           => $gdpr->type,
            'exam'           => $gdpr->exam ? [
                'public_id' => $gdpr->exam->public_id,
                'name'      => $gdpr->exam->name,
            ] : null,
            'version'        => $this->mapVersion($gdpr->activeVersion),
        ]);
    }

    /**
     * POST /gdpr
     * Crea nuovo GDPR con prima versione
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'   => 'required|string|max:255',
            'type'    => 'required|in:inscription,exam',
            'id_exam' => 'nullable|exists:exams,public_id',
            'text'    => 'required|string',
        ]);

        // Sanitizzazione HTML completa (XSS protection via HTMLPurifier)
        $validated['text'] = Purifier::clean($validated['text']);

        $examId = null;
        if (!empty($validated['id_exam'])) {
            $examId = Exam::where('public_id', $validated['id_exam'])->value('id');
        }

        $gdpr = DB::transaction(function () use ($validated, $examId) {
            $gdpr = GDPR::create([
                'title'   => $validated['title'],
                'type'    => $validated['type'],
                'id_exam' => $examId,
            ]);

            GDPRVersion::create([
                'id_gdpr' => $gdpr->id,
                'text'    => $validated['text'],
                'version' => 1,
                'active'  => true,
            ]);

            return $gdpr;
        });

        Log::info('[GDPRController] Nuovo GDPR creato', [
            'public_id' => $gdpr->public_id,
            'type'      => $gdpr->type,
            'user_id'   => auth()->id(),
        ]);

        return response()->json(
            $this->mapGdprWithVersions($gdpr->fresh(['exam', 'versions'])),
            201
        );
    }

    /**
     * POST /gdpr/{public_id}/versions
     * Crea nuova versione per un GDPR esistente
     */
    public function storeVersion(Request $request, string $publicId)
    {
        $gdpr = GDPR::where('public_id', $publicId)->firstOrFail();

        $validated = $request->validate([
            'text'   => 'required|string',
            'active' => 'boolean',
        ]);

        $validated['text'] = Purifier::clean($validated['text']);
        $isActive = (bool) ($validated['active'] ?? false);

        $version = DB::transaction(function () use ($gdpr, $validated, $isActive) {
            $nextVersion = ($gdpr->versions()->max('version') ?? 0) + 1;

            if ($isActive) {
                $gdpr->versions()->where('active', true)->update(['active' => false]);
            }

            return GDPRVersion::create([
                'id_gdpr' => $gdpr->id,
                'text'    => $validated['text'],
                'version' => $nextVersion,
                'active'  => $isActive,
            ]);
        });

        Log::info('[GDPRController] Nuova versione GDPR creata', [
            'gdpr_public_id' => $gdpr->public_id,
            'version'        => $version->version,
            'active'         => $version->active,
            'user_id'        => auth()->id(),
        ]);

        return response()->json($this->mapVersion($version), 201);
    }

    private function mapGdpr(GDPR $gdpr): array
    {
        return [
            'public_id'      => $gdpr->public_id,
            'title'          => $gdpr->title,
            'type'           => $gdpr->type,
            'id_exam'        => $gdpr->id_exam,
            'exam'           => $gdpr->exam ? [
                'public_id' => $gdpr->exam->public_id,
                'name'      => $gdpr->exam->name,
            ] : null,
            'active_version' => $gdpr->activeVersion
                ? $this->mapVersion($gdpr->activeVersion)
                : null,
            'created_at'     => $gdpr->created_at,
        ];
    }

    private function mapGdprWithVersions(GDPR $gdpr): array
    {
        return [
            ...$this->mapGdpr($gdpr),
            'versions' => $gdpr->versions->map(fn($v) => $this->mapVersion($v))->values(),
        ];
    }

    private function mapVersion(GDPRVersion $version): array
    {
        return [
            'public_id'  => $version->public_id,
            'version'    => $version->version,
            'text'       => $version->text,
            'active'     => (bool) $version->active,
            'created_at' => $version->created_at,
        ];
    }
}
