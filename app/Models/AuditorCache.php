<?php

// app/Models/AuditorCache.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditorCache extends Model
{
    protected $table = 'auditors_cache';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'id', 'public_id', 'name', 'surname', 'phone', 'email', 'fiscal_code',
        'type', 'is_examiner', 'is_auditor', 'is_decision_maker',
        'has_qualified_status', 'access', 'employee',
        'start_workship', 'end_workship', 'app1_updated_at', 'synced_at',
    ];

    protected $casts = [
        'is_decision_maker'    => 'boolean',
        'has_qualified_status' => 'boolean',
        'start_workship'       => 'date',
        'end_workship'         => 'date',
        'app1_updated_at'      => 'datetime',
        'synced_at'            => 'datetime',
    ];
}
