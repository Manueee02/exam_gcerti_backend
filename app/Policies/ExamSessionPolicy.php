<?php

namespace App\Policies;

use App\Models\ExamSession;
use App\Models\ExamSessionCandidateRun;
use App\Models\PlannedExam;
use App\Models\User;
use App\Policies\Concerns\ResolvesExaminerAssignment;

class ExamSessionPolicy
{
    use ResolvesExaminerAssignment;

    public function end(User $user, ExamSession $session): bool
    {
        return $this->isStaffForSession($user, $session);
    }

    public function enableCandidate(User $user, ExamSession $session): bool
    {
        return $this->isStaffForSession($user, $session);
    }

    public function viewCandidateLog(User $user, ExamSession $session): bool
    {
        return $this->isStaffForSession($user, $session);
    }

    private function isStaffForSession(User $user, ExamSession $session): bool
    {
        if ($this->isAdminOrSuperAdmin($user)) {
            return true;
        }

        if (!$this->isExaminerRole($user)) {
            return false;
        }

        $plannedExam = PlannedExam::find($session->id_planned_exam);

        return $plannedExam && $this->userIsAssignedExaminer($user, $plannedExam->id_examiner);
    }

    // ── Lato candidato: invariato rispetto a prima ──────────────────────
    public function accessCandidateExam(User $user, ExamSession $session): bool
    {
        return $this->ownRun($user, $session) !== null;
    }

    public function submitAnswer(User $user, ExamSession $session): bool
    {
        $run = $this->ownRun($user, $session);
        return $run !== null && $run->status === 'in_progress';
    }

    private function ownRun(User $user, ExamSession $session): ?ExamSessionCandidateRun
    {
        $candidate = $user->candidate;
        if (!$candidate) return null;

        return ExamSessionCandidateRun::where('id_exam_session', $session->id)
            ->where('id_candidate', $candidate->id)
            ->first();
    }
}
