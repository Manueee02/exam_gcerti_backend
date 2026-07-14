<?php

namespace App\Http\Middleware;

use App\Models\ExamSession;
use App\Models\ExamSessionCandidateRun;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCandidateOwnsExamSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $sessionPublicId = $request->route('sessionPublicId');

        $session = ExamSession::where('public_id', $sessionPublicId)->first();

        if (!$session) {
            return response()->json([
                'success' => false,
                'message' => 'Sessione non trovata',
            ], 404);
        }

        $candidate = $request->user()?->candidate;

        if (!$candidate) {
            return response()->json([
                'success' => false,
                'message' => 'Utente non associato a un candidato',
            ], 403);
        }

        $run = ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidate->id)
            ->first();

        if (!$run) {
            return response()->json([
                'success' => false,
                'message' => "Non sei iscritto a questa sessione d'esame",
            ], 403);
        }

        $request->attributes->set('exam_session', $session);
        $request->attributes->set('exam_session_run', $run);

        return $next($request);
    }
}
