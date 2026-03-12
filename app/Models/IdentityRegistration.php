<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per plan: identity registration request from public triage when ID not found.
 * Client may submit optional name, birth_year, client_category. Staff verify and accept or reject.
 */
class IdentityRegistration extends Model
{
    protected $fillable = [
        'program_id',
        'session_id',
        'name',
        'birth_year',
        'client_category',
        'id_type',
        'id_number_encrypted',
        'id_number_last4',
        'id_verified_at',
        'id_verified_by_user_id',
        'status',
        'client_id',
        'requested_at',
        'resolved_at',
        'resolved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
            'id_verified_at' => 'datetime',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'session_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function idVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_verified_by_user_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeForProgram(Builder $query, int $programId): Builder
    {
        return $query->where('program_id', $programId);
    }
}
