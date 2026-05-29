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
        'id_gdpr_version',
        'id_candidate',
        'accepted_at',
        'date',
        'accepted',
        'preference',
        'id_user',
    ];

    protected $casts = [
        'id_gdpr_version' => 'integer',
        'id_candidate' => 'integer',
        'id_user'      => 'integer',
        'accepted_at'  => 'datetime',
        'date'         => 'date',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    public function gdpr()
    {
        return $this->belongsTo(GDPRVersion::class, 'id_gdpr_version', 'id');
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class, 'id_candidate', 'id');
    }
}
