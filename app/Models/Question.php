<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Question extends Model
{
    // Rimuovi HasUuids — l'id rimane intero auto-increment

    protected $table = 'questions';

    protected $fillable = [
        'public_id',   // <-- rinomina da public_key a public_id (coerente col resto)
        'exam_id',
        'text',
        'type',
        'area',
        'level',
        'points',
    ];

    protected static function booted(): void
    {
        static::creating(function (Question $question) {
            if (empty($question->public_id)) {
                $question->public_id = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function answers()
    {
        return $this->hasMany(Answer::class, 'id_question');
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }
}
