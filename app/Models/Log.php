<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Log extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'level',
        'message',
        'context',
        'created_at'
    ];

    protected $casts = [
        'context' => 'array',
    ];
}
