<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per plan: identity registration request from public triage when ID not found.
 * Client may submit optional first_name, middle_name, last_name, birth_date, address, client_category. Staff verify and accept or reject.
 */
class IdentityRegistration extends Model
{
    protected $fillable = [
        'program_id',
        'request_type',
        'session_id',
        'token_id',
        'track_id',
        'first_name',
        'middle_name',
        'last_name',
        'birth_date',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'client_category',
        'mobile_encrypted',
        'mobile_hash',
        'id_verified',
        'id_verified_by_user_id',
        'id_verified_at',
        'status',
        'client_id',
        'requested_at',
        'resolved_at',
        'resolved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
            'id_verified_at' => 'datetime',
        ];
    }

    /**
     * Single display name for exports/reports.
     */
    public function getDisplayNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name ?? '',
            $this->middle_name ?? '',
            $this->last_name ?? '',
        ]))) ?: 'Unknown';
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class, 'session_id');
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(ServiceTrack::class, 'track_id');
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
