<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DecisionsMakerMedia extends Model
{
    use HasFactory;

    protected $table = 'decisions_makers_media';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'id_decision_maker',
        'id_media',
        'type',
    ];

    protected $casts = [
        'id_decision_maker' => 'integer',
        'id_media' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function decisionsMaker()
    {
        return $this->belongsTo(DecisionsMaker::class, 'id_decision_maker', 'id');
    }

    public function media()
    {
        return $this->belongsTo(Media::class, 'id_media', 'id');
    }
}
