<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
class ExamSessionCandidateRun extends Model
{
    protected $table = 'exam_session_candidate_runs';

    protected $fillable = [
        'id_exam_session',
        'id_candidate',
        'status',
        'current_step',
        'started_at',
        'ended_at',
        'score',
        'passed',
        'current_exam_area_id',
        'current_exam_level_id',
        'current_step_started_at',
        'level_started_by_candidate_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'level_started_by_candidate_at' => 'datetime',
    ];

    // RELAZIONI

    public function session()
    {
        return $this->belongsTo(ExamSession::class, 'id_exam_session');
    }

    public function questions()
    {
        return $this->hasMany(ExamSessionCandidateQuestion::class, 'id_candidate_run');
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class, 'id_candidate');
    }
}
