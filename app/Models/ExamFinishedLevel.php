<?php
// app/Models/ExamFinishedLevel.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamFinishedLevel extends Model
{
    protected $table = 'exam_finished_levels';
    public $timestamps = false;

    protected $fillable = [
        'id_exam_finished',
        'id_exam_finished_area',
        'id_exam_level',
        'level_name_snapshot',
        'level_order_snapshot',
        'rule_n_questions_snapshot',
        'rule_duration_minutes_snapshot',
        'rule_passing_score_snapshot',
        'correct',
        'total',
        'passed',
        'reason',
        'is_final_incomplete_level',
        'duration_used_seconds',
    ];

    protected $casts = [
        'passed' => 'boolean',
        'is_final_incomplete_level' => 'boolean',
    ];

    public function questions()
    {
        return $this->hasMany(ExamFinishedQuestion::class, 'id_exam_finished_level');
    }
}
