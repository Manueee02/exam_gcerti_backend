<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Media extends Model
{
    use HasFactory;

    protected $table = 'media';

    protected $fillable = [
        'original_name',
        'path',
        'url',
        'mime_type',
        'size',
        'disk',
        'md5_hash',
        'pending_delete_at',
        'is_temporary',
    ];

    protected $casts = [
        'pending_delete_at' => 'datetime',
        'is_temporary'      => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ── Scopes ────────────────────────────────────────────────────────────────

    /** File marcati per eliminazione soft */
    public function scopePendingDelete(Builder $query): Builder
    {
        return $query->whereNotNull('pending_delete_at');
    }

    /** File temporanei non ancora confermati */
    public function scopeTemporary(Builder $query): Builder
    {
        return $query->where('is_temporary', true);
    }

    /** File temporanei orfani più vecchi di $minutes minuti */
    public function scopeOrphanTemporary(Builder $query, int $minutes = 60): Builder
    {
        return $query
            ->where('is_temporary', true)
            ->where('created_at', '<', now()->subMinutes($minutes));
    }

    /** File pending delete orfani più vecchi di $minutes minuti */
    public function scopeOrphanPendingDelete(Builder $query, int $minutes = 60): Builder
    {
        return $query
            ->whereNotNull('pending_delete_at')
            ->where('pending_delete_at', '<', now()->subMinutes($minutes));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isPendingDelete(): bool
    {
        return !is_null($this->pending_delete_at);
    }

    public function isTemporary(): bool
    {
        return (bool) $this->is_temporary;
    }
}
