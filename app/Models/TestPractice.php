<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TestPractice extends Model
{
    use HasFactory;

    protected $table = 'test_practice';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'id_candidates_exam',
        'note',
        'exam_log',
        'time_finished',
        'closed_session',
    ];

    protected $casts = [
        'id_candidates_exam' => 'integer',
        'exam_log' => 'array',
        'closed_session' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function candidateExam()
    {
        return $this->belongsTo(CandidateExam::class, 'id_candidates_exam', 'id');
    }

    public function media()
    {
        return $this->hasMany(TestPracticeMedia::class, 'id_test_practice', 'id');
    }
}
