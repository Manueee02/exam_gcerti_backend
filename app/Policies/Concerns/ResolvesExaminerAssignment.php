<?php

namespace App\Policies\Concerns;

use App\Models\AuditorCache;
use App\Models\User;
use App\Models\UserCreatedExaminerDecisionmaker;
use App\Models\UserRole;
use App\Models\ExamSession;
use App\Models\PlannedExam;
use Illuminate\Support\Collection;

trait ResolvesExaminerAssignment
{
    protected function isAdminOrSuperAdmin(User $user): bool
    {
        $roleName = UserRole::find($user->id_role)?->name;
        return in_array($roleName, ['admin', 'superAdmin'], true);
    }

    protected function isExaminerRole(User $user): bool
    {
        return UserRole::find($user->id_role)?->name === 'examiner';
    }

    /**
     * Verifica che l'utente autenticato sia effettivamente l'esaminatore
     * assegnato a un dato planned_exam, risalendo la catena reale:
     * users -> user_created_examiner_decisionmaker -> auditors_cache -> id_examiner.
     *
     * Controlla anche is_active e is_examiner sulla cache: un auditor
     * disattivato o che ha perso il flag esaminatore su App1 non deve
     * poter operare qui anche se la riga di collegamento esiste ancora.
     */
    protected function userIsAssignedExaminer(User $user, ?int $idExaminer): bool
    {
        if (!$idExaminer) {
            return false;
        }

        $link = UserCreatedExaminerDecisionmaker::where('id_user', $user->id)->first();
        if (!$link) {
            return false;
        }

        $auditorCache = AuditorCache::where('public_id', $link->auditor_public_id)->first();

        if (!$auditorCache || !$auditorCache->is_active || $auditorCache->is_examiner !== 'true') {
            return false;
        }

        return (int) $auditorCache->id === (int) $idExaminer;
    }

    /**
     * Risolve l'AuditorCache collegato all'utente autenticato, se esiste
     * ed è attivo. Centralizza la risalita users -> user_created_examiner_decisionmaker
     * -> auditors_cache già usata in userIsAssignedExaminer, per non ripeterla
     * ogni volta che serve anche il controllo lato decisionmaker.
     */
    protected function resolveLinkedAuditorCache(User $user): ?AuditorCache
    {
        $link = UserCreatedExaminerDecisionmaker::where('id_user', $user->id)->first();
        if (!$link) {
            return null;
        }

        $auditorCache = AuditorCache::where('public_id', $link->auditor_public_id)->first();

        if (!$auditorCache || !$auditorCache->is_active) {
            return null;
        }

        return $auditorCache;
    }

    protected function userIsAssignedDecisionMaker(User $user, ?int $idDecisionMaker): bool
    {
        if (!$idDecisionMaker) {
            return false;
        }

        $auditorCache = $this->resolveLinkedAuditorCache($user);

        if (!$auditorCache || !$auditorCache->is_decision_maker) {
            return false;
        }

        return (int) $auditorCache->id === (int) $idDecisionMaker;
    }

    /**
     * True se l'utente è l'esaminatore O il decisionmaker assegnato al
     * planned_exam a cui appartiene la sessione — indipendentemente dal
     * ruolo applicativo (id_role), perché quel che conta è l'assegnazione
     * reale sincronizzata da App1, non l'etichetta del ruolo.
     */
    protected function isExaminerOrDecisionMakerForSession(User $user, \App\Models\ExamSession $session): bool
    {
        $plannedExam = \App\Models\PlannedExam::find($session->id_planned_exam);
        if (!$plannedExam) {
            return false;
        }

        return $this->userIsAssignedExaminer($user, $plannedExam->id_examiner)
            || $this->userIsAssignedDecisionMaker($user, $plannedExam->id_decision_maker);
    }

    /**
     * Id (interno, non public_id) dei planned_exams su cui l'utente risulta
     * assegnato come esaminatore o decisionmaker. Usato per filtrare le
     * liste — se l'utente non ha alcun AuditorCache collegato, torna una
     * collezione vuota (nessun risultato, non un errore).
     */
    protected function assignedPlannedExamIds(User $user): \Illuminate\Support\Collection
    {
        $auditorCache = $this->resolveLinkedAuditorCache($user);
        if (!$auditorCache) {
            return collect();
        }

        return \App\Models\PlannedExam::where('id_examiner', $auditorCache->id)
            ->orWhere('id_decision_maker', $auditorCache->id)
            ->pluck('id');
    }
}
