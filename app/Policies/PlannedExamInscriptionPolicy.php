<?php

namespace App\Policies;

use App\Models\User;
use App\Models\PlannedExamInscription;

class PlannedExamInscriptionPolicy
{
    public function view(User $user, PlannedExamInscription $inscription): bool
    {
        $user->loadMissing('role', 'candidate');

        // Admin e superAdmin vedono tutto
        if (in_array($user->role->name, ['admin', 'superAdmin'])) {
            return true;
        }

        // User → solo iscrizioni del proprio candidato
        if ($user->role->name === 'user') {
            return $user->candidate &&
                $user->candidate->id === $inscription->id_candidate;
        }

        return false;
    }

// DOPO
    public function forceStatusUpdate(User $user, PlannedExamInscription $inscription): bool
    {
        $user->loadMissing('role');
        return in_array($user->role->name, ['admin', 'superAdmin'], true);
    }
}
