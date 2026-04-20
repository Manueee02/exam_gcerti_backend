<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Exam extends Model
{
    use HasFactory;

    protected $table = 'exams';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'type',
        'name',
        'description',
        'cost',
        'active',
        'color'
    ];

    protected $casts = [
        'active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tests()
    {
        return $this->hasMany(ExamTest::class, 'id_exam', 'id');
    }

    public function plannedExams()
    {
        return $this->hasMany(PlannedExam::class, 'id_exam', 'id');
    }

    public function payments()
    {
        return $this->hasMany(CandidatePayment::class, 'id_exam', 'id');
    }

    public function getRouteKeyName()
    {
        return 'public_id';
    }
}
