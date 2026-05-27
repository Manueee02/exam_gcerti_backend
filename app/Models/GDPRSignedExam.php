<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GDPRSignedExam extends Model
{
    use HasFactory;

    protected $table = 'GDPR_signed_exams';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'id_GDPR',
        'id_candidates_exam',
        'date',
        'accepted',
        'preference',
    ];

    protected $casts = [
        'id_GDPR' => 'integer',
        'id_candidates_exam' => 'integer',
        'date' => 'date',
        'accepted' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function gdpr()
    {
        return $this->belongsTo(GDPRVersion::class, 'id_GDPR', 'id');
    }

    public function candidateExam()
    {
        return $this->belongsTo(CandidateExam::class, 'id_candidates_exam', 'id');
    }
}
