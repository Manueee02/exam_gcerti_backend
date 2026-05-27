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
        'type',
        'id_exam',
    ];

    protected $casts = [
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


    public function versions()
    {
        return $this->hasMany(GDPRVersion::class, 'id_gdpr', 'id');
    }

    public function activeVersion()
    {
        return $this->hasOne(GDPRVersion::class, 'id_gdpr', 'id')->where('active', true);
    }
}
