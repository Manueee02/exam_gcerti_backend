<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DecisionsMaker extends Model
{
    use HasFactory;

    protected $table = 'decisions_makers';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'id_user',
        'name',
        'surname',
        'email',
        'phone',
        'active',
    ];

    protected $casts = [
        'id_user' => 'integer',
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'id_user', 'id');
    }

    public function media()
    {
        return $this->hasMany(DecisionsMakerMedia::class, 'id_decision_maker', 'id');
    }

    public function plannedExams()
    {
        return $this->hasMany(PlannedExam::class, 'id_decision_maker', 'id');
    }

    public function candidateExams()
    {
        return $this->hasManyThrough(
            CandidateExam::class,
            PlannedExam::class,
            'id_decision_maker', // FK in planned_exams
            'id_planned_exam',   // FK in candidates_exams
            'id',                // PK DecisionsMaker
            'id'                 // PK PlannedExam
        );
    }

}
