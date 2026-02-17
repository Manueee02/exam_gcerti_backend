<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailVerificationToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token',
        'expires_at',
        'sent_at',
        'used_at',
    ];

    protected $dates = [
        'expires_at',
        'sent_at',
        'used_at',
    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
