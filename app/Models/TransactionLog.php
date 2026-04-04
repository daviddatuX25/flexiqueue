<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit trail (per 04-DATA-MODEL).
 * No UPDATE or DELETE allowed — append-only.
 */
class TransactionLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'session_id',
        'station_id',
        'staff_user_id',
        'action_type',
        'previous_station_id',
        'next_station_id',
        'remarks',
        'metadata',
        'created_at',
    ];

    /**
     * Set created_at on create (table has NOT NULL; we do not use updated_at).
     */
    protected static function booted(): void
    {
        static::creating(function (TransactionLog $log): void {
            if (empty($log->created_at)) {
                $log->created_at = now();
            }
        });

        static::created(function (TransactionLog $log): void {
            // Only fire on edge in auto sync mode
            $edgeModeService = app(\App\Services\EdgeModeService::class);
            if (! $edgeModeService->isEdge()) {
                return;
            }

            $state = \App\Models\EdgeDeviceState::current();
            if ($state->sync_mode !== 'auto' || ! $state->session_active) {
                return;
            }

            $session = $log->session;
            if ($session) {
                event(new \App\Events\EdgeSyncableEventCreated($log, $session));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    public function previousStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'previous_station_id');
    }

    public function nextStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'next_station_id');
    }

    /**
     * Append-only: updates are not allowed.
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \RuntimeException('TransactionLog is append-only. Updates are not permitted.');
    }

    /**
     * Append-only: deletes are not allowed.
     */
    public function delete(): ?bool
    {
        throw new \RuntimeException('TransactionLog is append-only. Deletes are not permitted.');
    }
}
