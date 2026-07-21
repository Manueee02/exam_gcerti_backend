<?php
// app/Models/ExamFinishedQuestion.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamFinishedQuestion extends Model
{
    protected $table = 'exam_finished_questions';
    public $timestamps = false;

    protected $fillable = [
        'id_exam_finished_level',
        'id_question',
        'question_text_snapshot',
        'position',
        'was_answered',
    ];

    protected $casts = [
        'was_answered' => 'boolean',
    ];

    public function options()
    {
        return $this->hasMany(ExamFinishedQuestionOption::class, 'id_exam_finished_question');
    }
}
