<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditorCache extends Model
{
    protected $table = 'auditors_cache';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = true;


    protected $fillable = [
        'id', 'public_id', 'name', 'surname', 'phone', 'email', 'fiscal_code',
        'type', 'is_examiner', 'is_auditor', 'is_decision_maker',
        'has_qualified_status', 'is_active', 'access', 'employee',
        'start_workship', 'end_workship', 'app1_updated_at', 'synced_at',
    ];

    protected $casts = [
        'is_decision_maker'    => 'boolean',
        'has_qualified_status' => 'boolean',
        'is_active'            => 'boolean',
        'start_workship'       => 'date',
        'end_workship'         => 'date',
        'app1_updated_at'      => 'datetime',
        'synced_at'            => 'datetime',
    ];

    /**
     * Scope: solo record attivi (examiner/DM attualmente rilevanti).
     * Da usare ovunque si propongono nuove selezioni (dropdown, referenceData).
     * NON usare negli esami storici già pianificati, dove va mostrato comunque
     * il riferimento anche se l'auditor è stato disattivato nel frattempo.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
