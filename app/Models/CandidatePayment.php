<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidatePayment extends Model
{
    use HasFactory;

    protected $table = 'candidates_payments';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'id_exam',
        'id_candidate',
        'order_number',
        'id_transaction',
        'amount',
        'currency',
        'payment_state',
        'payment_method',
    ];

    protected $casts = [
        'id_exam' => 'integer',
        'id_candidate' => 'integer',
        'amount' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function candidate()
    {
        return $this->belongsTo(Candidate::class, 'id_candidate', 'id');
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class, 'id_exam', 'id');
    }

    public function examsAssigned()
    {
        return $this->hasMany(CandidateExam::class, 'id_candidate_payment', 'id');
    }
}
