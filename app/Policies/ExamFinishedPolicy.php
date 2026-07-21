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

    /**
     * Solo il decisionmaker assegnato al planned_exam della sessione può
     * approvare/rifiutare — nessun override admin/superAdmin, per design
     * esplicito: la delibera è una responsabilità solo sua.
     */
    public function approve(User $user, ExamFinished $examFinished): bool
    {
        $session = ExamSession::find($examFinished->id_exam_session);
        if (!$session) {
            return false;
        }

        $plannedExam = \App\Models\PlannedExam::find($session->id_planned_exam);
        if (!$plannedExam) {
            return false;
        }

        return $this->userIsAssignedDecisionMaker($user, $plannedExam->id_decision_maker);
    }

    /**
     * Export: chiunque possa vedere l'esame può anche scaricarlo — candidato
     * proprietario, staff assegnato alla sessione, o admin/superAdmin.
     * Riusa le due ability già esistenti invece di riscrivere la logica.
     */
    public function export(User $user, ExamFinished $examFinished): bool
    {
        return $this->viewOwn($user, $examFinished) || $this->view($user, $examFinished);
    }
}
