<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class GDPR extends Model
{
    use HasFactory;

    protected $table = 'GDPR';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'title',
        'text',
        'type',
        'id_exam',
        'active',
    ];

    protected $casts = [
        'active'     => 'boolean',
        'id_exam'    => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $gdpr) {
            if (empty($gdpr->public_id)) {
                $gdpr->public_id = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class, 'id_exam', 'id');
    }

    public function signed()
    {
        return $this->hasMany(GDPRSigned::class, 'id_GDPR', 'id');
    }

    public function signedExams()
    {
        return $this->hasMany(GDPRSignedExam::class, 'id_GDPR', 'id');
    }
}
