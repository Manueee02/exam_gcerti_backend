<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PlannedExamInscription extends Model
{
    use HasFactory;

    protected $table = 'planned_exams_inscription';

    protected $fillable = [
        'id_planned_exam',
        'status',
        'id_candidate',
        'document',
        'invoice',
    ];

    protected $casts = [
        'id_planned_exam' => 'integer',
        'document' => 'integer',
        'invoice' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function plannedExam()
    {
        return $this->belongsTo(PlannedExam::class, 'id_planned_exam');
    }

    public function documentMedia()
    {
        return $this->belongsTo(Media::class, 'document');
    }

    public function invoiceMedia()
    {
        return $this->belongsTo(Media::class, 'invoice');
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class, 'id_candidate');
    }
}
