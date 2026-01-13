<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Chat extends Model
{
    use HasFactory;

    protected $table = 'chat';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'id_canidates_exam',
        'id_examiner',
        'messages',
    ];

    protected $casts = [
        'id_canidates_exam' => 'integer',
        'id_examiner' => 'integer',
        'messages' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function candidateExam()
    {
        return $this->belongsTo(CandidateExam::class, 'id_canidates_exam', 'id');
    }

    public function examiner()
    {
        return $this->belongsTo(Examiner::class, 'id_examiner', 'id');
    }
}
