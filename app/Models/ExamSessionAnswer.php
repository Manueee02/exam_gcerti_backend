<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamSessionAnswer extends Model
{
    protected $table = 'exam_session_answers';

    protected $fillable = [
        'id_exam_session',
        'id_exam_session_step',
        'id_question',
        'id_candidate',
        'answer',
        'is_correct',
        'time_spent_seconds',
    ];

    protected $casts = [
        'answer' => 'array',
        'is_correct' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];


    // RELAZIONI

    public function session()
    {
        return $this->belongsTo(ExamSession::class, 'id_exam_session');
    }
}
