<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Exam;

class ExamPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Exam $exam): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Exam $exam): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, Exam $exam): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(User $user): bool
    {
        $user->loadMissing('role');
        return in_array($user->role->name, ['admin', 'superAdmin']);
    }
}
