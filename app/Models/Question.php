<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    protected $table = 'questions';

    protected $fillable = [
        'public_id',
        'exam_id',
        'exam_area_id',
        'exam_level_id',
        'text',
        'type',
        'points',
    ];

    // ── Relazioni ──────────────────────────────────────────────────────────────

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(ExamArea::class, 'exam_area_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(ExamLevel::class, 'exam_level_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class, 'id_question');
    }
}
