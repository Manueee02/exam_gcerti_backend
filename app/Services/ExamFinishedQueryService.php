<?php

namespace App\Services;

use App\Models\Candidate;
use App\Models\Exam;
use App\Models\ExamFinished;
use App\Models\ExamSession;
use App\Models\ExamSessionCandidateRun;
use App\Models\PlannedExam;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ExamFinishedQueryService
{
    // =========================================================
    // CANDIDATO
    // =========================================================

    public function listForCandidate(int $candidateId): Collection
    {
        return ExamFinished::where('id_candidate', $candidateId)
            ->orderByDesc('generated_at')
            ->get([
                'public_id', 'id_exam', 'exam_name_snapshot',
                'run_status', 'session_status', 'started_at', 'ended_at',
                'total_duration_seconds', 'generated_at',
            ]);
    }

    public function showForCandidate(string $publicId, int $candidateId): ExamFinished
    {
        return ExamFinished::with(['areas.levels.questions.options'])
            ->where('public_id', $publicId)
            ->where('id_candidate', $candidateId)
            ->firstOrFail();
    }

    // =========================================================
    // EXAMINER / DECISIONMAKER (filtrato) — ADMIN (non filtrato)
    // =========================================================

    /**
     * @param Collection|null $plannedExamIds  null = nessun filtro (uso admin)
     */
    public function listSessions(?Collection $plannedExamIds): Collection
    {
        $query = ExamSession::query()
            ->with(['plannedExam.exam:id,name'])
            ->orderByDesc('started_at');

        if ($plannedExamIds !== null) {
            if ($plannedExamIds->isEmpty()) {
                Log::warning('listSessions: nessun planned_exam assegnato per l\'utente, lista vuota');
                return collect();
            }
            $query->whereIn('id_planned_exam', $plannedExamIds);
        }

        return $query->get()->map(function (ExamSession $session) {
            return [
                'session_public_id' => $session->public_id,
                'exam_name'         => $session->plannedExam->exam->name ?? null,
                'session_status'    => $session->status,
                'started_at'        => $session->started_at,
                'ended_at'          => $session->ended_at,
            ];
        });
    }

    /**
     * Riepilogo candidati di UNA sessione: nome, cognome, stato del run,
     * public_id dell'exam_finished (null se non ancora generato — run
     * ancora in corso o mai avviato).
     */
    public function sessionCandidatesSummary(int $sessionId): Collection
    {
        $runs = ExamSessionCandidateRun::where('id_exam_session', $sessionId)
            ->with('candidate:id,public_id,name,surname')
            ->get();

        if ($runs->isEmpty()) {
            Log::warning('sessionCandidatesSummary: nessun run trovato per la sessione', [
                'session_id' => $sessionId,
            ]);
        }

        $finishedByCandidate = ExamFinished::where('id_exam_session', $sessionId)
            ->pluck('public_id', 'id_candidate');

        return $runs->map(function (ExamSessionCandidateRun $run) use ($finishedByCandidate) {
            return [
                'candidate_public_id'      => $run->candidate->public_id ?? null,
                'candidate_name'           => $run->candidate->name ?? null,
                'candidate_surname'        => $run->candidate->surname ?? null,
                'run_status'               => $run->status,
                'exam_finished_public_id'  => $finishedByCandidate->get($run->id_candidate),
            ];
        })->values();
    }

    public function show(string $publicId): ExamFinished
    {
        return ExamFinished::with(['areas.levels.questions.options'])
            ->where('public_id', $publicId)
            ->firstOrFail();
    }
}
