<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminMailNotification extends Model
{
    use HasFactory;

    protected $table = 'admin_mail_notification';

    protected $primaryKey = 'id';

    // timestamps già presenti nella tabella
    public $timestamps = true;

    protected $fillable = [
        'id_user',
        'type',
        'active',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relazione con la tabella users
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'id_user');
    }
}
