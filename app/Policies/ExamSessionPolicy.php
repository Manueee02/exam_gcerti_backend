<?php

namespace App\Policies;

use App\Models\AuditorCache;
use App\Models\ExamSession;
use App\Models\PlannedExam;
use App\Models\User;
use App\Models\UserCreatedExaminerDecisionmaker;

class ExamSessionPolicy
{
    /**
     * Risolve l'id numerico App1 dell'auditor associato a un utente locale.
     * Usa auditors_cache come fonte di verità locale, evitando qualsiasi
     * chiamata HTTP verso App1 a runtime.
     * Restituisce null se l'utente non ha un auditor associato o se
     * l'auditor non è presente in cache.
     */
    private function resolveAuditorId(User $user): ?int
    {
        $link = UserCreatedExaminerDecisionmaker::where('id_user', $user->id)->first();

        if (!$link) {
            return null;
        }

        $auditor = AuditorCache::where('public_id', $link->auditor_public_id)->first();

        return $auditor?->id;
    }

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
            $auditorId = $this->resolveAuditorId($user);
            return $auditorId !== null && $plannedExam->id_examiner === $auditorId;
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
            $auditorId = $this->resolveAuditorId($user);
            return $auditorId !== null && $session->plannedExam->id_examiner === $auditorId;
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
            $auditorId = $this->resolveAuditorId($user);
            return $auditorId !== null && $session->plannedExam->id_examiner === $auditorId;
        }

        return false;
    }

    /**
     * Admin/examiner può vedere il log completo di un candidato
     */
    public function viewCandidateLog(User $user, ExamSession $session): bool
    {
        $user->loadMissing('role');

        if (in_array($user->role->name, ['admin', 'superAdmin'])) {
            return true;
        }

        if ($user->role->name === 'examiner') {
            $auditorId = $this->resolveAuditorId($user);
            return $auditorId !== null && $session->plannedExam->id_examiner === $auditorId;
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

        if (!$user->candidate) {
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

        if (!$user->candidate) {
            return false;
        }

        return $session
            ->candidateRuns()
            ->where('id_candidate', $user->candidate->id)
            ->exists();
    }
}
