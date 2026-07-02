<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlannedExam extends Model
{
    use HasFactory;

    protected $table = 'planned_exams';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'id_exam',
        'id_test_center',
        'id_examiner',
        'id_decision_maker',
        'date',
        'time',
        'end_time',
        'location',
        'active_exam_session'
    ];

    protected $casts = [
        'id_exam' => 'integer',
        'id_test_center' => 'integer',
        'id_examiner' => 'integer',
        'id_decision_maker' => 'integer',
/*        'date' => 'date',*/
        'time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class, 'id_exam', 'id');
    }

    public function testCenter()
    {
        return $this->belongsTo(TestCenter::class, 'id_test_center', 'id');
    }

    public function examiner()
    {
        return $this->belongsTo(Examiner::class, 'id_examiner', 'id');
    }

    public function decisionMaker()
    {
        return $this->belongsTo(DecisionsMaker::class, 'id_decision_maker', 'id');
    }

    public function candidateExams()
    {
        return $this->hasMany(PlannedExamCandidate::class, 'id_planned_exam', 'id');
    }

    public function chats()
    {
        return $this->hasManyThrough(
            Chat::class,
            CandidateExam::class,
            'id_planned_exam',    // FK CandidateExam -> PlannedExam
            'id_canidates_exam',  // FK Chat -> CandidateExam
            'id',                 // PK PlannedExam
            'id'                  // PK CandidateExam
        );
    }

    public function candidates()
    {
        return $this->belongsToMany(
            Candidate::class,
            'planned_exams_candidates',
            'id_planned_exam',
            'id_candidate'
        )->withTimestamps();
    }

    public function plannedExamCandidates()
    {
        return $this->hasMany(PlannedExamCandidate::class, 'id_planned_exam');
    }

    public function inscriptions()
    {
        return $this->hasMany(PlannedExamInscription::class, 'id_planned_exam');
    }

}
