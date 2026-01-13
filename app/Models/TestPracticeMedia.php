<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TestPracticeMedia extends Model
{
    use HasFactory;

    protected $table = 'test_practice_media';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;

    protected $fillable = [
        'id_media',
        'id_test_practice',
    ];

    protected $casts = [
        'id_media' => 'integer',
        'id_test_practice' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function testPractice()
    {
        return $this->belongsTo(TestPractice::class, 'id_test_practice', 'id');
    }

    public function media()
    {
        return $this->belongsTo(Media::class, 'id_media', 'id');
    }
}
