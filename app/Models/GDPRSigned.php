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
        'date',
        'accepted',
        'preference',
    ];

    protected $casts = [
        'id_GDPR' => 'integer',
        'id_candidate' => 'integer',
        'date' => 'date',
        'accepted' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function gdpr()
    {
        return $this->belongsTo(GDPR::class, 'id_GDPR', 'id');
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class, 'id_candidate', 'id');
    }
}
