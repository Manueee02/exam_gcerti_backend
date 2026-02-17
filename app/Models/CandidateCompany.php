<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidateCompany extends Model
{
    use HasFactory;

    protected $table = 'candidates_company';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'id_candidates',

        // tipo fatturazione
        'billing_type',

        // libero professionista
        'piva',

        // azienda
        'company_piva',
        'company_social_reason',
        'company_mail',
        'company_province',
        'company_legal_address',
        'company_city',
        'company_phone',
    ];

    protected $casts = [
        'id_candidates' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function candidate()
    {
        return $this->belongsTo(Candidate::class, 'id_candidates', 'id');
    }
}
