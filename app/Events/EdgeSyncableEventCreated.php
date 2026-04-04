<?php

namespace App\Events;

use App\Models\Session;
use App\Models\TransactionLog;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a TransactionLog is created on edge.
 * Carries the log entry and its associated session for sync payload building.
 *
 * This is NOT a broadcast event — it is an internal event consumed by
 * PushEdgeEventToCentral listener only.
 */
class EdgeSyncableEventCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public TransactionLog $transactionLog,
        public Session $session,
    ) {}
}
