<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GDPRSigned extends Model
{
    use HasFactory;

    protected $table = 'GDPR_signed';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'id_GDPR',
        'id_candidate',
        'id_exam',
        'accepted_at',
        'date',
        'accepted',
        'preference',
        'id_user',
    ];

    protected $casts = [
        'id_GDPR'      => 'integer',
        'id_candidate' => 'integer',
        'id_exam'      => 'integer',
        'id_user'      => 'integer',
        'accepted_at'  => 'datetime',
        'date'         => 'date',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    public function gdpr()
    {
        return $this->belongsTo(GDPRVersion::class, 'id_GDPR', 'id');
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class, 'id_candidate', 'id');
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class, 'id_exam', 'id');
    }
}
