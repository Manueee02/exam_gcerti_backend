<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamExtractionRule extends Model
{
    use HasFactory;

    protected $table = 'exam_extraction_rules';

    protected $fillable = [
        'exam_area_id',
        'exam_level_id',
        'n_questions',
        'duration_minutes',
        'passing_score',
    ];

    public function area(): BelongsTo
    {
        return $this->belongsTo(ExamArea::class, 'exam_area_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(ExamLevel::class, 'exam_level_id');
    }
}
