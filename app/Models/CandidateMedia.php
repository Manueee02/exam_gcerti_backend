<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidateMedia extends Model
{
    use HasFactory;

    protected $table = 'candidates_media';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'id_candidate',
        'id_media',
        'type',
    ];

    protected $casts = [
        'id_candidate' => 'integer',
        'id_media' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function candidate()
    {
        return $this->belongsTo(Candidate::class, 'id_candidate', 'id');
    }

    public function media()
    {
        return $this->belongsTo(Media::class, 'id_media', 'id');
    }
}
