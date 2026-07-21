<?php

namespace App\Policies;

use App\Models\ExamFinished;
use App\Models\ExamSession;
use App\Models\User;
use App\Policies\Concerns\ResolvesExaminerAssignment;

class ExamFinishedPolicy
{
    use ResolvesExaminerAssignment;

    /**
     * Candidato: può vedere solo il proprio esame concluso.
     */
    public function viewOwn(User $user, ExamFinished $examFinished): bool
    {
        $candidate = $user->candidate;
        return $candidate && (int) $examFinished->id_candidate === (int) $candidate->id;
    }

    /**
     * Examiner/decisionmaker assegnati alla sessione, oppure admin/superAdmin.
     * Usato sia per il dettaglio del singolo exam_finished sia per il
     * riepilogo di una sessione specifica.
     */
    public function view(User $user, ExamFinished $examFinished): bool
    {
        if ($this->isAdminOrSuperAdmin($user)) {
            return true;
        }

        $session = ExamSession::find($examFinished->id_exam_session);
        return $session && $this->isExaminerOrDecisionMakerForSession($user, $session);
    }

    public function viewSession(User $user, ExamSession $session): bool
    {
        if ($this->isAdminOrSuperAdmin($user)) {
            return true;
        }

        return $this->isExaminerOrDecisionMakerForSession($user, $session);
    }

    /**
     * Lista delle proprie sessioni: chiunque abbia un AuditorCache collegato
     * (examiner o decisionmaker) può chiamarla — il filtro su QUALI sessioni
     * vede è nel service (assignedPlannedExamIds), non qui. Admin/superAdmin
     * passano sempre, ma per loro va usata viewAllSessions per la vista globale.
     */
    public function viewSessionsList(User $user): bool
    {
        if ($this->isAdminOrSuperAdmin($user)) {
            return true;
        }

        return $this->resolveLinkedAuditorCache($user) !== null;
    }

    public function viewAllSessions(User $user): bool
    {
        return $this->isAdminOrSuperAdmin($user);
    }
}
