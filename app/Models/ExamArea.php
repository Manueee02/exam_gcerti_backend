<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamArea extends Model
{
    use HasFactory;

    protected $table = 'exam_areas';

    protected $fillable = [
        'exam_id',
        'name',
        'label',
        'order'
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'exam_id');
    }

    public function levels(): HasMany
    {
        return $this->hasMany(ExamLevel::class, 'exam_area_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'exam_area_id');
    }

    public function extractionRules(): HasMany
    {
        return $this->hasMany(ExamExtractionRule::class, 'exam_area_id');
    }
}
