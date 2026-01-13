<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamTest extends Model
{
    use HasFactory;

    protected $table = 'exams_test';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'id_exam',
        'test',
    ];

    protected $casts = [
        'id_exam' => 'integer',
        'test' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function exam()
    {
        return $this->belongsTo(Exam::class, 'id_exam', 'id');
    }
}
