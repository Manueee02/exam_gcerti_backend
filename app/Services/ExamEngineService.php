<?php

namespace App\Services;

use App\Models\ExamSession;
use App\Models\ExamSessionCandidateRun;
use App\Models\ExamSessionCandidateQuestion;
use App\Models\PlannedExam;
use App\Models\PlannedExamCandidate;
use App\Models\Question;
use Illuminate\Support\Facades\DB;

class ExamEngineService
{
    public function startSession(string $plannedExamPublicId): ExamSession
    {
        return DB::transaction(function () use ($plannedExamPublicId) {

            // 1. Recupera planned exam
            $plannedExam = PlannedExam::where('public_id', $plannedExamPublicId)
                ->firstOrFail();

            // 2. Crea sessione
            $session = ExamSession::create([
                'id_planned_exam' => $plannedExam->id,
                'id_exam' => $plannedExam->id_exam,
                'status' => 'live',
                'started_at' => now(),
            ]);

            // 3. Prendi candidati
            $candidates = PlannedExamCandidate::where('id_planned_exam', $plannedExamId)->get();

            // 4. Crea run
            $runs = [];

            foreach ($candidates as $candidate) {
                $runs[] = ExamSessionCandidateRun::create([
                    'id_exam_session' => $session->id,
                    'id_candidate' => $candidate->id_candidate,
                    'status' => 'pending',
                ]);
            }

            // 5. Prendi domande (MVP RANDOM)
            $questions = Question::where('exam_id', $plannedExam->id_exam)
                ->inRandomOrder()
                ->limit(20)
                ->get();

            // 6. Assegna domande per candidato
            foreach ($runs as $run) {

                foreach ($questions as $index => $question) {

                    ExamSessionCandidateQuestion::create([
                        'id_candidate_run' => $run->id,
                        'id_question' => $question->id,
                        'position' => $index + 1,
                    ]);
                }
            }

            return $session;
        });
    }
}
