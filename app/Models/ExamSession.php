<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamSession extends Model
{
    protected $table = 'exam_sessions';

    protected $fillable = [
        'public_id',
        'id_planned_exam',
        'id_exam',
        'status',
        'started_at',
        'ended_at',
        'join_url',
        'session_snapshot',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'session_snapshot' => 'array',
    ];

    // 🔗 Relations

    public function plannedExam(): BelongsTo
    {
        return $this->belongsTo(PlannedExam::class, 'id_planned_exam');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ExamSessionParticipant::class, 'id_exam_session');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ExamSessionLog::class, 'id_exam_session');
    }
}
