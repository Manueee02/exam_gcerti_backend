<?php

namespace App\Policies;

use App\Models\User;

class QuestionPolicy
{
    /**
     * Accesso generale (lista, show, ecc.)
     */
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function import(User $user): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Helper centralizzato
     */
    private function isAdmin(User $user): bool
    {
        $user->loadMissing('role');

        return in_array($user->role->name, ['admin', 'superAdmin']);
    }
}
