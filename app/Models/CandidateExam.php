<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidateExam extends Model
{
    use HasFactory;

    protected $table = 'candidates_exams';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'id_planned_exam',
        'id_candidate',
        'id_candidate_payment',
    ];

    protected $casts = [
        'id_planned_exam' => 'integer',
        'id_candidate' => 'integer',
        'id_candidate_payment' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function candidate()
    {
        return $this->belongsTo(Candidate::class, 'id_candidate', 'id');
    }

    public function payment()
    {
        return $this->belongsTo(CandidatePayment::class, 'id_candidate_payment', 'id');
    }

    public function plannedExam()
    {
        return $this->belongsTo(PlannedExam::class, 'id_planned_exam', 'id');
    }

    public function chats()
    {
        return $this->hasMany(Chat::class, 'id_canidates_exam', 'id');
    }

    public function testOral()
    {
        return $this->hasOne(TestOral::class, 'id_candidates_exam', 'id');
    }

    public function testPractice()
    {
        return $this->hasOne(TestPractice::class, 'id_candidates_exam', 'id');
    }

    public function testWritten()
    {
        return $this->hasOne(TestWritten::class, 'id_candidates_exam', 'id');
    }






}
