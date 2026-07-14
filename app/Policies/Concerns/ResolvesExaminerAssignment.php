<?php

namespace App\Policies\Concerns;

use App\Models\AuditorCache;
use App\Models\User;
use App\Models\UserCreatedExaminerDecisionmaker;
use App\Models\UserRole;

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
}
