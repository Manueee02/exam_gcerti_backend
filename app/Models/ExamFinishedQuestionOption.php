<?php
// app/Models/ExamFinishedQuestionOption.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamFinishedQuestionOption extends Model
{
    protected $table = 'exam_finished_question_options';
    public $timestamps = false;

    protected $fillable = [
        'id_exam_finished_question',
        'id_answer',
        'answer_text_snapshot',
        'is_correct_snapshot',
        'was_selected_by_candidate',
        'display_order',
    ];

    protected $casts = [
        'is_correct_snapshot' => 'boolean',
        'was_selected_by_candidate' => 'boolean',
    ];
}
