<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Question extends Model
{
    use HasUuids;

    protected $table = 'questions';

    protected $fillable = [
        'public_key',
        'exam_id',
        'text',
        'type',
        'area',
        'level',
        'points',
    ];

    protected $casts = [
        'public_key' => 'string',
    ];

    /**
     * Route model binding su public_key invece che id
     */
    public function getRouteKeyName()
    {
        return 'public_key';
    }

    /**
     * Relazione: una domanda ha molte risposte
     */
    public function answers()
    {
        return $this->hasMany(Answer::class);
    }

    /**
     * Relazione: appartiene a un esame
     */
    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }
}
