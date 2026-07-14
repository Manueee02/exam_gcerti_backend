<?php

namespace App\Policies;

use App\Models\PlannedExam;
use App\Models\User;
use App\Policies\Concerns\ResolvesExaminerAssignment;

class PlannedExamPolicy
{
    use ResolvesExaminerAssignment;

    public function start(User $user, PlannedExam $plannedExam): bool
    {
        if ($this->isAdminOrSuperAdmin($user)) {
            return true;
        }

        if (!$this->isExaminerRole($user)) {
            return false;
        }

        return $this->userIsAssignedExaminer($user, $plannedExam->id_examiner);
    }
}
