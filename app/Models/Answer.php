<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Answer extends Model
{
    use HasUuids;

    protected $table = 'answers';

    protected $fillable = [
        'public_key',
        'question_id',
        'text',
        'is_correct',
        'order',
    ];

    protected $casts = [
        'public_key' => 'string',
        'is_correct' => 'boolean',
    ];

    /**
     * Route model binding su public_key
     */
    public function getRouteKeyName()
    {
        return 'public_key';
    }

    /**
     * Relazione: appartiene a una domanda
     */
    public function question()
    {
        return $this->belongsTo(Question::class);
    }
}
