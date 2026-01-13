<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TestOral extends Model
{
    use HasFactory;

    protected $table = 'test_oral';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'valutation',
        'exam_log',
        'id_candidates_exam',
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
}
