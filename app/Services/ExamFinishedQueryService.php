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
    public function __construct(
        protected ExamEngineService $examEngine
    ) {}

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
                'approval_status', 'approved_at',
            ]);
    }

    public function findForCandidate(string $publicId, int $candidateId): ExamFinished
    {
        return ExamFinished::with(['areas.levels.questions.options', 'candidate:id,public_id,name,surname'])
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

        $sessions = $query->get();
        if ($sessions->isEmpty()) {
            return collect();
        }

        $sessionIds = $sessions->pluck('id');

        $runsBySession = ExamSessionCandidateRun::whereIn('id_exam_session', $sessionIds)
            ->with('candidate:id,public_id,name,surname')
            ->get()
            ->groupBy('id_exam_session');

        $finishedBySession = ExamFinished::whereIn('id_exam_session', $sessionIds)
            ->get(['public_id', 'id_exam_session', 'id_candidate', 'approval_status'])
            ->groupBy('id_exam_session');

        return $sessions->map(function (ExamSession $session) use ($runsBySession, $finishedBySession) {
            $sessionRuns = $runsBySession->get($session->id, collect());

            if ($sessionRuns->isEmpty()) {
                Log::warning('listSessions: sessione senza run candidati associati', [
                    'session_id' => $session->id,
                ]);
            }

            $finishedForSession = $finishedBySession->get($session->id, collect())
                ->keyBy('id_candidate');

            $candidates = $sessionRuns->map(function (ExamSessionCandidateRun $run) use ($finishedForSession) {
                $finished = $finishedForSession->get($run->id_candidate);

                return [
                    'candidate_public_id'      => $run->candidate->public_id ?? null,
                    'candidate_name'           => $run->candidate->name ?? null,
                    'candidate_surname'        => $run->candidate->surname ?? null,
                    'run_status'               => $run->status,
                    'exam_finished_public_id'  => $finished->public_id ?? null,
                    'approval_status'          => $finished->approval_status ?? null,
                ];
            })->values();

            return [
                'session_public_id' => $session->public_id,
                'exam_name'         => $session->plannedExam->exam->name ?? null,
                'session_status'    => $session->status,
                'started_at'        => $session->started_at,
                'ended_at'          => $session->ended_at,
                'candidates'        => $candidates,
            ];
        });
    }

    public function find(string $publicId): ExamFinished
    {
        return ExamFinished::with(['areas.levels.questions.options', 'candidate:id,public_id,name,surname'])
            ->where('public_id', $publicId)
            ->firstOrFail();
    }



    // =========================================================
    // LOG — allegati alla stessa risposta di find/findForCandidate,
    // non un endpoint separato
    // =========================================================

    public function withEvents(ExamFinished $examFinished, bool $includeSensitiveDetails): array
    {
        $data = $examFinished->toArray();
        $data['events'] = [];

        $session = ExamSession::find($examFinished->id_exam_session);

        if (!$session) {
            Log::error('withEvents: sessione non trovata per exam_finished, impossibile recuperare i log', [
                'exam_finished_id' => $examFinished->id,
                'id_exam_session'  => $examFinished->id_exam_session,
            ]);
            return $data;
        }

        try {
            $log = $this->examEngine->getActivityLog(
                $session->public_id,
                $examFinished->id_candidate,
                $includeSensitiveDetails
            );
            $data['events'] = $log['events'] ?? [];
        } catch (\Throwable $e) {
            Log::error('withEvents: recupero log fallito', [
                'exam_finished_id' => $examFinished->id,
                'error'            => $e->getMessage(),
            ]);
        }

        return $data;
    }

    // =========================================================
    // APPROVAZIONE DECISIONMAKER — irreversibile: una sola decisione
    // =========================================================

    public function approve(ExamFinished $examFinished, int $auditorCacheId, ?string $note): ExamFinished
    {
        if ($examFinished->approval_status !== 'pending') {
            Log::warning('approve: tentativo di rivalutare un exam_finished già deciso', [
                'exam_finished_id' => $examFinished->id,
                'current_status'   => $examFinished->approval_status,
            ]);
            throw new \Exception('Questo esame è già stato valutato dal deliberante e non può essere modificato.');
        }

        $examFinished->update([
            'approval_status' => 'approved',
            'approved_by'     => $auditorCacheId,
            'approved_at'     => now(),
            'approval_note'   => $note,
        ]);

        return $examFinished;
    }

    public function reject(ExamFinished $examFinished, int $auditorCacheId, string $note): ExamFinished
    {
        if ($examFinished->approval_status !== 'pending') {
            Log::warning('reject: tentativo di rivalutare un exam_finished già deciso', [
                'exam_finished_id' => $examFinished->id,
                'current_status'   => $examFinished->approval_status,
            ]);
            throw new \Exception('Questo esame è già stato valutato dal deliberante e non può essere modificato.');
        }

        $examFinished->update([
            'approval_status' => 'rejected',
            'approved_by'     => $auditorCacheId,
            'approved_at'     => now(),
            'approval_note'   => $note,
        ]);

        return $examFinished;
    }

    public function findForPdf(string $publicId): ExamFinished
    {
        return ExamFinished::with(['areas.levels.questions.options', 'candidate:id,public_id,name,surname'])
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    public function findForPdfCandidate(string $publicId, int $candidateId): ExamFinished
    {
        return ExamFinished::with(['areas.levels.questions.options', 'candidate:id,public_id,name,surname'])
            ->where('public_id', $publicId)
            ->where('id_candidate', $candidateId)
            ->firstOrFail();
    }
}
