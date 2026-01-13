<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TestWritten extends Model
{
    use HasFactory;

    protected $table = 'test_written';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'id_candidates_exam',
        'question_answers',
        'exam_log',
        'results',
    ];

    protected $casts = [
        'id_candidates_exam' => 'integer',
        'question_answers' => 'array',
        'exam_log' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function candidateExam()
    {
        return $this->belongsTo(CandidateExam::class, 'id_candidates_exam', 'id');
    }
}
