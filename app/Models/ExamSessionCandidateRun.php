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
        'passed'
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',

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
}
