<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Candidate extends Model
{
    use HasFactory;

    // Nome della tabella se non segue la convenzione plurale di Laravel
    protected $table = 'candidates';

    // Chiave primaria
    protected $primaryKey = 'id';

    // Se la chiave primaria è auto-increment (SERIAL)
    public $incrementing = true;

    // Tipo della chiave primaria
    protected $keyType = 'int';

    // Timestamp automatici
    public $timestamps = true;
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    // Campi assegnabili in massa
    protected $fillable = [
        'id_user',
        'name',
        'surname',
        'email',
        'phone',
        'fiscal_code',
        'sex',
        'birthdate',
        'birthplace',
        'birthprovince',
        'birthcommun',
        'is_foreign',
        'birthcountry',
        'residence_address',
        'residence_city',
        'residence_province',
        'residence_zip',
        'residence_country',
        'active',
    ];


    // Cast dei campi
    protected $casts = [
        'id_user' => 'integer',
        'birthdate' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'active' => 'string',
    ];

    // Relazione con l'utente
    public function user()
    {
        return $this->belongsTo(User::class, 'id_user', 'id');
    }

    // Relazione con company
    public function companies()
    {
        return $this->hasMany(CandidateCompany::class, 'id_candidates', 'id');
    }

    // Relazione con exams
    public function exams()
    {
        return $this->hasMany(CandidateExam::class, 'id_candidate', 'id');
    }

    // Relazione con payments
    public function payments()
    {
        return $this->hasMany(CandidatePayment::class, 'id_candidate', 'id');
    }

    // Relazione con media
    public function media()
    {
        return $this->hasMany(CandidateMedia::class, 'id_candidate', 'id');
    }

    public function gdprSigned()
    {
        return $this->hasMany(GDPRSigned::class, 'id_candidate', 'id');
    }

    public function gdprSignedExams()
    {
        return $this->hasManyThrough(
            GDPRSignedExam::class,
            CandidateExam::class,
            'id_candidate', // FK in candidates_exams
            'id_candidates_exam', // FK in GDPR_signed_exams
            'id', // PK Candidate
            'id'  // PK CandidateExam
        );
    }


}
