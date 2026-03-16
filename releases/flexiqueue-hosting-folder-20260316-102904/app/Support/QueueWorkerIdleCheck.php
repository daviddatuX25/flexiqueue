<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Heuristic: when using database queue, detect if no worker is processing jobs
 * (pending jobs older than 2 minutes). Used to decide whether to return 503 or
 * run TTS generation synchronously via dispatchSync.
 */
class QueueWorkerIdleCheck
{
    public static function appearsIdle(): bool
    {
        if (config('queue.default') !== 'database') {
            return false;
        }

        $connection = config('queue.connections.database.connection') ?? config('database.default');
        if (! is_string($connection) || trim($connection) === '') {
            $connection = (string) config('database.default');
        }
        $table = config('queue.connections.database.table', 'jobs');
        $queue = config('queue.connections.database.queue', 'default');
        $cutoff = now()->subMinutes(2)->timestamp;

        return DB::connection($connection)->table($table)
            ->where('queue', $queue)
            ->whereNull('reserved_at')
            ->where('created_at', '<', $cutoff)
            ->exists();
    }
}
