<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class GDPRVersion extends Model
{
    use HasFactory;

    protected $table = 'gdpr_versions';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'id_gdpr',
        'text',
        'version',
        'active',
    ];

    protected $casts = [
        'id_gdpr'    => 'integer',
        'version'    => 'integer',
        'active'     => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $gv) {
            if (empty($gv->public_id)) {
                $gv->public_id = (string) Str::uuid();
            }
        });
    }

    public function gdpr()
    {
        return $this->belongsTo(GDPR::class, 'id_gdpr', 'id');
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
