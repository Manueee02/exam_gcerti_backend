<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PlannedExamCandidate extends Model
{
    use HasFactory;

    protected $table = 'planned_exams_candidates';

    protected $fillable = [
        'id_candidate',
        'id_planned_exam',
    ];

    protected $casts = [
        'id_candidate' => 'integer',
        'id_planned_exam' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function plannedExam()
    {
        return $this->belongsTo(PlannedExam::class, 'id_planned_exam');
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class, 'id_candidate');
    }
}
