<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\GDPR;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mews\Purifier\Facades\Purifier;

class GDPRController extends Controller
{
    /**
     * GET /gdpr
     * Lista GDPR con filtri opzionali: type, id_exam, active
     */
    public function index(Request $request)
    {
        $query = GDPR::with('exam');

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('id_exam')) {
            $exam = Exam::where('public_id', $request->id_exam)->first();
            $query->where('id_exam', $exam?->id);
        }

        if ($request->filled('active')) {
            $query->where('active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN));
        }

        $gdprs = $query->orderBy('created_at', 'desc')->get();

        return response()->json($gdprs->map(fn($g) => $this->mapGdpr($g)));
    }

    /**
     * GET /gdpr/{public_id}
     * Dettaglio GDPR
     */
    public function show(string $publicId)
    {
        $gdpr = GDPR::with('exam')->where('public_id', $publicId)->firstOrFail();
        return response()->json($this->mapGdpr($gdpr));
    }

    /**
     * GET /gdpr/active?type=inscription&id_exam={public_id}
     * GDPR attivo per type + id_exam, con fallback al globale
     */
    public function active(Request $request)
    {
        $request->validate([
            'type'    => 'required|in:inscription,exam',
            'id_exam' => 'nullable|string',
        ]);

        $type   = $request->type;
        $examId = null;

        if ($request->filled('id_exam')) {
            $examId = Exam::where('public_id', $request->id_exam)->value('id');
        }

        // Cerca GDPR attivo specifico per questo esame
        $gdpr = null;
        if ($examId) {
            $gdpr = GDPR::where('type', $type)
                ->where('id_exam', $examId)
                ->where('active', true)
                ->first();
        }

        // Fallback: GDPR globale (id_exam = null)
        if (!$gdpr) {
            $gdpr = GDPR::where('type', $type)
                ->whereNull('id_exam')
                ->where('active', true)
                ->first();
        }

        if (!$gdpr) {
            return response()->json(['message' => 'Nessun GDPR attivo trovato'], 404);
        }

        return response()->json($this->mapGdpr($gdpr->load('exam')));
    }

    /**
     * POST /gdpr
     * Crea nuova versione GDPR (immutabile, non modificabile/eliminabile)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'   => 'required|string|max:255',
            'text'    => 'required|string',
            'type'    => 'required|in:inscription,exam',
            'id_exam' => 'nullable|exists:exams,public_id',
            'active'  => 'boolean',
        ]);

        // Sanitizzazione HTML completa (XSS protection via HTMLPurifier)
        $validated['text'] = Purifier::clean($validated['text']);

        // Risolvi public_id esame → id interno
        $examId = null;
        if (!empty($validated['id_exam'])) {
            $examId = Exam::where('public_id', $validated['id_exam'])->value('id');
        }

        // DOPO
        $isActive = (bool) ($validated['active'] ?? false);

        $gdpr = DB::transaction(function () use ($validated, $examId, $isActive) {
            // Se active = true → disattiva tutti gli altri con stessa combinazione
            if ($isActive) {
                GDPR::where('type', $validated['type'])
                    ->where(function ($q) use ($examId) {
                        if ($examId !== null) {
                            $q->where('id_exam', $examId);
                        } else {
                            $q->whereNull('id_exam');
                        }
                    })
                    ->where('active', true)
                    ->update(['active' => false]);
            }

            return GDPR::create([
                'title'   => $validated['title'],
                'text'    => $validated['text'],
                'type'    => $validated['type'],
                'id_exam' => $examId,
                'active'  => $isActive,
            ]);
        });

        Log::info('[GDPRController] Nuovo GDPR creato', [
            'public_id' => $gdpr->public_id,
            'type'      => $gdpr->type,
            'active'    => $gdpr->active,
            'user_id'   => auth()->id(),
        ]);

        return response()->json($this->mapGdpr($gdpr->fresh('exam')), 201);
    }

    private function mapGdpr(GDPR $gdpr): array
    {
        return [
            'public_id'  => $gdpr->public_id,
            'title'      => $gdpr->title,
            'text'       => $gdpr->text,
            'type'       => $gdpr->type,
            'active'     => (bool) $gdpr->active,
            'id_exam'    => $gdpr->id_exam,
            'exam'       => $gdpr->exam ? [
                'public_id' => $gdpr->exam->public_id,
                'name'      => $gdpr->exam->name,
            ] : null,
            'created_at' => $gdpr->created_at,
        ];
    }
}
