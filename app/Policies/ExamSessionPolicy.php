<?php
namespace App\Policies;

use App\Models\ExamSession;
use App\Models\PlannedExam;
use App\Models\User;

class ExamSessionPolicy
{
    /**
     * Examiner può avviare sessione
     */
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

    /**
     * Examiner può chiudere sessione
     */
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

    /**
     * Examiner abilita candidato
     */
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
     * Candidato può entrare nella sessione
     */
    public function accessCandidateExam(User $user, ExamSession $session): bool
    {
        $user->loadMissing('role');

        if ($user->role->name !== 'user') {
            return false;
        }

        return $session
            ->candidateRuns()
            ->where('id_candidate', $user->candidate->id)
            ->exists();
    }

    /**
     * Candidato può rispondere
     */
    public function submitAnswer(User $user, ExamSession $session): bool
    {
        $user->loadMissing('role');

        if ($user->role->name !== 'user') {
            return false;
        }

        return $session
            ->candidateRuns()
            ->where('id_candidate', $user->candidate->id)
            ->exists();
    }
}
