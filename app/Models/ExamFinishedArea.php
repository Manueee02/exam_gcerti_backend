<?php
// app/Models/ExamFinishedArea.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamFinishedArea extends Model
{
    protected $table = 'exam_finished_areas';
    public $timestamps = false;

    protected $fillable = [
        'id_exam_finished',
        'id_exam_area',
        'area_name_snapshot',
        'area_label_snapshot',
        'area_order_snapshot',
        'area_status',
        'id_exam_level_certified',
        'level_certified_name_snapshot',
        'level_certified_label_snapshot',
        'level_certified_order_snapshot',
        'correct',
        'total',
    ];

    public function levels()
    {
        return $this->hasMany(ExamFinishedLevel::class, 'id_exam_finished_area');
    }
}
