<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExaminerMedia extends Model
{
    use HasFactory;

    protected $table = 'examiners_media';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'type',
        'id_examiner',
        'id_media',
    ];

    protected $casts = [
        'id_examiner' => 'integer',
        'id_media' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function examiner()
    {
        return $this->belongsTo(Examiner::class, 'id_examiner', 'id');
    }

    public function media()
    {
        return $this->belongsTo(Media::class, 'id_media', 'id');
    }
}
