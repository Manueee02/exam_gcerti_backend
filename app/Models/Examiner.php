<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Examiner extends Model
{
    use HasFactory;

    protected $table = 'examiners';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'name',
        'surname',
        'email',
        'phone',
        'id_user',
        'active',
    ];

    protected $casts = [
        'id_user' => 'integer',
        'active' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'id_user', 'id');
    }

    public function chats()
    {
        return $this->hasMany(Chat::class, 'id_examiner', 'id');
    }

    public function media()
    {
        return $this->hasMany(ExaminerMedia::class, 'id_examiner', 'id');
    }

    public function plannedExams()
    {
        return $this->hasMany(PlannedExam::class, 'id_examiner', 'id');
    }

    public function chatsCandidateExams()
    {
        return $this->hasManyThrough(
            CandidateExam::class,
            Chat::class,
            'id_examiner', // FK in Chat
            'id',          // FK in CandidateExam
            'id',          // PK Examiner
            'id_canidates_exam' // FK in Chat
        );
    }
}
