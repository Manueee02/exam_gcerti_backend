<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamSessionLog extends Model
{
    protected $table = 'exam_session_logs';

    public $timestamps = false;

    protected $fillable = [
        'id_exam_session',
        'event_type',
        'actor_type',
        'actor_id',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    // 🔗 Relations

    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'id_exam_session');
    }
}
