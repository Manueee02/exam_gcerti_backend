<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GDPR extends Model
{
    use HasFactory;

    protected $table = 'GDPR';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'title',
        'text',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function signed()
    {
        return $this->hasMany(GDPRSigned::class, 'id_GDPR', 'id');
    }

    public function signedExams()
    {
        return $this->hasMany(GDPRSignedExam::class, 'id_GDPR', 'id');
    }
}
