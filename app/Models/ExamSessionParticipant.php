<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamSessionParticipant extends Model
{
    protected $table = 'exam_session_participants';

    protected $fillable = [
        'id_exam_session',
        'id_candidate',
        'id_examiner',
        'role',
        'status',
        'joined_at',
        'left_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    // 🔗 Relations

    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'id_exam_session');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'id_candidate');
    }

    public function examiner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_examiner');
    }
}
