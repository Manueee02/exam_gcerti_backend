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
        'id_candidate',
        'status',
        'note',
        'document',
        'invoice',
        'unsigned_document',
        'unsigned_invoice',
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

    /** Template da firmare caricato dall'admin – documento */
    public function unsignedDocumentMedia()
    {
        return $this->belongsTo(Media::class, 'unsigned_document');
    }

    /** Template da firmare caricato dall'admin – fattura */
    public function unsignedInvoiceMedia()
    {
        return $this->belongsTo(Media::class, 'unsigned_invoice');
    }
}
