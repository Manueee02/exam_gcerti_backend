<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserCreatedExaminerDecisionmaker extends Model
{
    use HasFactory;

    protected $table = 'user_created_examiner_decisionmaker';

    protected $fillable = [
        'auditor_public_id',
        'created_at',
        'updated_at',
        'id_user'

    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'id_user');
    }
}
