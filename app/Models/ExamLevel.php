<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamLevel extends Model
{
    protected $table = 'exam_levels';

    protected $fillable = [
        'exam_area_id',
        'name',
        'label',
        'order'
    ];

    public function area(): BelongsTo
    {
        return $this->belongsTo(ExamArea::class, 'exam_area_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'exam_level_id');
    }

    public function extractionRules(): HasMany
    {
        return $this->hasMany(ExamExtractionRule::class, 'exam_level_id');
    }
}
