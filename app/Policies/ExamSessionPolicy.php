<?php

namespace App\Policies;

use App\Models\ExamSession;
use App\Models\PlannedExam;
use App\Models\User;

class ExamSessionPolicy
{
    public function start(User $user, PlannedExam $plannedExam): bool
    {
        $user->loadMissing('role');

        if (in_array($user->role->name, ['admin', 'superAdmin'])) {
            return true;
        }

        if ($user->role->name === 'examiner') {
            return $plannedExam->id_examiner === $user->id;
        }

        return false;
    }

    public function end(User $user, ExamSession $session): bool
    {
        $user->loadMissing('role');

        if (in_array($user->role->name, ['admin', 'superAdmin'])) {
            return true;
        }

        if ($user->role->name === 'examiner') {
            return $session->plannedExam->id_examiner === $user->id;
        }

        return false;
    }

    public function enableCandidate(User $user, ExamSession $session): bool
    {
        $user->loadMissing('role');

        if (in_array($user->role->name, ['admin', 'superAdmin'])) {
            return true;
        }

        if ($user->role->name === 'examiner') {
            return $session->plannedExam->id_examiner === $user->id;
        }

        return false;
    }

    /**
     * Chi puo' vedere il log completo (con is_correct) di un candidato:
     * stessa regola di end()/enableCandidate(). Eredita lo stesso limite
     * gia' discusso sul confronto id_examiner/$user->id per il ruolo
     * 'examiner' — non corretto qui di proposito, e' un task separato.
     */
    public function viewCandidateLog(User $user, ExamSession $session): bool
    {
        $user->loadMissing('role');

        if (in_array($user->role->name, ['admin', 'superAdmin'])) {
            return true;
        }

        if ($user->role->name === 'examiner') {
            return $session->plannedExam->id_examiner === $user->id;
        }

        return false;
    }

    public function accessCandidateExam(User $user, ExamSession $session): bool
    {
        $user->loadMissing('role');

        if ($user->role->name !== 'user') {
            return false;
        }

        if (!$user->candidate) {
            return false;
        }

        return $session
            ->candidateRuns()
            ->where('id_candidate', $user->candidate->id)
            ->exists();
    }

    public function submitAnswer(User $user, ExamSession $session): bool
    {
        $user->loadMissing('role');

        if ($user->role->name !== 'user') {
            return false;
        }

        if (!$user->candidate) {
            return false;
        }

        return $session
            ->candidateRuns()
            ->where('id_candidate', $user->candidate->id)
            ->exists();
    }
}
