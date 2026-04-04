<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EdgeSyncQueueItem extends Model
{
    public $timestamps = false;

    protected $table = 'edge_sync_queue';

    protected $fillable = [
        'transaction_log_id',
        'session_id',
        'event_type',
        'payload',
        'attempts',
        'status',
        'last_attempted_at',
        'synced_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'last_attempted_at' => 'datetime',
            'synced_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeRetryable($query)
    {
        return $query->where('status', 'pending')
            ->where('attempts', '<', 5)
            ->orderBy('created_at');
    }

    public function markSent(): void
    {
        $this->update([
            'status' => 'sent',
            'synced_at' => now(),
        ]);
    }

    public function incrementAttempt(): void
    {
        $this->update([
            'attempts' => $this->attempts + 1,
            'last_attempted_at' => now(),
        ]);
    }

    public function markFailed(): void
    {
        $this->update([
            'status' => 'failed',
            'last_attempted_at' => now(),
        ]);
    }
}
