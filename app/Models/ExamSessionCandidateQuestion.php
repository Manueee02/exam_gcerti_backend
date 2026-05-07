<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamSessionCandidateQuestion extends Model
{
    protected $table = 'exam_session_candidate_questions';

    protected $fillable = [
        'id_candidate_run',
        'id_question',
        'id_exam_session_step',
        'position',
    ];


    // RELAZIONI

    public function run()
    {
        return $this->belongsTo(ExamSessionCandidateRun::class, 'id_candidate_run');
    }

    public function question()
    {
        return $this->belongsTo(Question::class, 'id_question');
    }
}
