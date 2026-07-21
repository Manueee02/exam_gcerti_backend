<?php
// app/Models/ExamFinished.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamFinished extends Model
{
    protected $table = 'exam_finished';
    public $timestamps = false; // solo generated_at, gestito esplicitamente

    protected $fillable = [
        'public_id',
        'id_exam_session',
        'id_exam',
        'id_candidate',
        'exam_name_snapshot',
        'exam_duration_minutes_snapshot',
        'session_status',
        'run_status',
        'started_at',
        'ended_at',
        'total_duration_seconds',
        'report_snapshot',
        'generated_at',
    ];

    protected $casts = [
        'report_snapshot' => 'array',
        'started_at'       => 'datetime',
        'ended_at'         => 'datetime',
        'generated_at'     => 'datetime',
    ];

    public function areas()
    {
        return $this->hasMany(ExamFinishedArea::class, 'id_exam_finished');
    }
}
