<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Queue client journey (bound to token).
 * Table: queue_sessions — avoids conflict with Laravel's sessions (HTTP).
 */
class Session extends Model
{
    protected $table = 'queue_sessions';

    protected $fillable = [
        'token_id',
        'client_id',
        'program_id',
        'track_id',
        'alias',
        'client_category',
        'current_station_id',
        'holding_station_id',
        'is_on_hold',
        'held_at',
        'held_order',
        'current_step_order',
        'override_steps',
        'station_queue_position',
        'status',
        'started_at',
        'queued_at_station',
        'no_show_attempts',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'queued_at_station' => 'datetime',
            'completed_at' => 'datetime',
            'override_steps' => 'array',
            'is_on_hold' => 'boolean',
            'held_at' => 'datetime',
        ];
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function serviceTrack(): BelongsTo
    {
        return $this->belongsTo(ServiceTrack::class, 'track_id');
    }

    public function currentStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'current_station_id');
    }

    public function holdingStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'holding_station_id');
    }

    public function transactionLogs(): HasMany
    {
        return $this->hasMany(TransactionLog::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['waiting', 'called', 'serving']);
    }

    /**
     * Sessions that consume station "client_capacity" (called reserves a slot; held does not).
     */
    public function scopeCapacityConsumingAtStation(Builder $query, int $stationId): Builder
    {
        return $query
            ->where('current_station_id', $stationId)
            ->whereIn('status', ['called', 'serving'])
            ->where(fn (Builder $q) => $q->whereNull('is_on_hold')->orWhere('is_on_hold', false));
    }

    public function isOnHold(): bool
    {
        return (bool) $this->is_on_hold;
    }

    /**
     * Whether this session is in the priority lane (PWD, Senior, Pregnant).
     */
    public function isPriorityCategory(): bool
    {
        return \App\Support\ClientCategory::isPriority($this->client_category);
    }
}
