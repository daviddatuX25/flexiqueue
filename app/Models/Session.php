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
        'program_id',
        'track_id',
        'alias',
        'client_category',
        'current_station_id',
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
        ];
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(Token::class);
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

    public function transactionLogs(): HasMany
    {
        return $this->hasMany(TransactionLog::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['waiting', 'called', 'serving']);
    }

    /**
     * Whether this session is in the priority lane (PWD, Senior, Pregnant).
     */
    public function isPriorityCategory(): bool
    {
        return \App\Support\ClientCategory::isPriority($this->client_category);
    }
}
