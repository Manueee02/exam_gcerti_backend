<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Answer extends Model
{

    protected $table = 'answers';

    protected $fillable = [
        'public_key',
        'id_question',
        'text',
        'is_correct',
    ];

    protected $casts = [
        'public_key' => 'string',
        'is_correct' => 'string',
    ];

    /**
     * Route model binding su public_key
     */
    public function getRouteKeyName()
    {
        return 'public_id';
    }

    /**
     * Relazione: appartiene a una domanda
     */
    public function question()
    {
        return $this->belongsTo(Question::class, 'id_question');
    }
}
