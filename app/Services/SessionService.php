<?php

namespace App\Services;

use App\Events\ClientArrived;
use App\Events\NowServing;
use App\Events\QueueLengthUpdated;
use App\Events\StationActivity;
use App\Events\StatusUpdate;
use App\Exceptions\StepsRemainingException;
use App\Exceptions\TokenInUseException;
use App\Models\Program;
use App\Models\Session;
use App\Support\ClientCategory;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use App\Models\TransactionLog;
use Illuminate\Support\Facades\DB;

/**
 * Per 03-FLOW-ENGINE and 08-API-SPEC-PHASE1 §3: session lifecycle (bind, call, transfer, complete, etc.).
 */
class SessionService
{
    public function __construct(
        private FlowEngine $flowEngine,
        private PinService $pinService
    ) {}

    /**
     * Bind token to a new session. Throws domain exceptions for 400/409 cases.
     *
     * @return array{session: \App\Models\Session, token: array}
     */
    public function bind(string $qrHash, int $trackId, ?string $clientCategory, int $staffUserId): array
    {
        $program = Program::where('is_active', true)->first();
        if (! $program) {
            throw new \InvalidArgumentException('No active program. Please activate a program first.');
        }

        $token = Token::where('qr_code_hash', $qrHash)->first();
        if (! $token) {
            throw new \InvalidArgumentException('Token not found.', 422);
        }

        if ($token->status === 'in_use') {
            $session = $token->currentSession;
            $session->load('currentStation');
            throw new TokenInUseException($session);
        }

        // Soft-deleted tokens excluded by Token model scope; token not found above.

        $track = $program->serviceTracks()->find($trackId);
        if (! $track) {
            throw new \InvalidArgumentException('Track does not belong to the active program.', 422);
        }

        $firstStep = $track->trackSteps()->where('step_order', 1)->first();
        if (! $firstStep) {
            throw new \InvalidArgumentException('Track has no steps defined.', 422);
        }

        return DB::transaction(function () use ($token, $program, $track, $firstStep, $clientCategory, $staffUserId) {
            $clientCategory = ClientCategory::normalize($clientCategory) ?? $clientCategory;
            $nextPos = $this->getNextQueuePositionAtStation($firstStep->station_id);
            $session = \App\Models\Session::create([
                'token_id' => $token->id,
                'program_id' => $program->id,
                'track_id' => $track->id,
                'alias' => $token->physical_id,
                'client_category' => $clientCategory,
                'current_station_id' => $firstStep->station_id,
                'current_step_order' => 1,
                'station_queue_position' => $nextPos,
                'status' => 'waiting',
                'queued_at_station' => now(),
            ]);

            $token->update([
                'status' => 'in_use',
                'current_session_id' => $session->id,
            ]);

            TransactionLog::create([
                'session_id' => $session->id,
                'station_id' => null,
                'staff_user_id' => $staffUserId,
                'action_type' => 'bind',
                'next_station_id' => $firstStep->station_id,
            ]);

            event(new ClientArrived($session->fresh(['currentStation', 'serviceTrack']), $firstStep->station_id));
            event(new QueueLengthUpdated($firstStep->station_id));

            return [
                'session' => $session->fresh(['currentStation', 'serviceTrack']),
                'token' => [
                    'physical_id' => $token->physical_id,
                    'status' => $token->status,
                ],
            ];
        });
    }

    /**
     * Per plan: Call session — sets status to 'called' (announce, not yet serving).
     * Client must physically show; staff clicks Serve to start.
     * Enforces client_capacity.
     *
     * @return array{session_id: int, alias: string, no_show_attempts: int, status: string}
     */
    public function call(Session $session, int $staffUserId): array
    {
        if ($session->status !== 'waiting') {
            throw new \InvalidArgumentException('Session is not waiting at current station.', 409);
        }

        $station = $session->currentStation;
        if (! $station) {
            throw new \InvalidArgumentException('Session has no current station.', 409);
        }

        $clientCapacity = (int) ($station->client_capacity ?? 1);
        $currentCount = Session::query()
            ->where('current_station_id', $station->id)
            ->whereIn('status', ['called', 'serving'])
            ->count();

        if ($currentCount >= $clientCapacity) {
            throw new \InvalidArgumentException("Station at capacity ({$clientCapacity}). Cannot call more clients.", 409);
        }

        $session->update(['status' => 'called']);

        TransactionLog::create([
            'session_id' => $session->id,
            'station_id' => $session->current_station_id,
            'staff_user_id' => $staffUserId,
            'action_type' => 'call',
        ]);

        $session = $session->fresh(['serviceTrack', 'currentStation']);
        $station = $session->currentStation;
        $isPriority = $session->isPriorityCategory();
        $message = $isPriority
            ? "{$session->alias} called from priority lane"
            : "{$session->alias} called";
        event(new StatusUpdate($session->current_station_id, $session));
        event(new QueueLengthUpdated($session->current_station_id));
        event(new StationActivity(
            $session->current_station_id,
            $station?->name ?? '—',
            $message,
            $session->alias,
            'call',
            now()->toIso8601String()
        ));

        return [
            'session_id' => $session->id,
            'alias' => $session->alias,
            'no_show_attempts' => $session->no_show_attempts ?? 0,
            'status' => 'called',
        ];
    }

    /**
     * Per plan: Serve session — client physically showed, staff clicks Serve.
     * From 'called' only. Logs check_in, sets serving.
     *
     * @return array{session: Session}
     */
    public function serve(Session $session, int $staffUserId): array
    {
        if ($session->status !== 'called') {
            throw new \InvalidArgumentException('Session is not in called state. Call the client first.', 409);
        }

        $session->update(['status' => 'serving']);

        TransactionLog::create([
            'session_id' => $session->id,
            'station_id' => $session->current_station_id,
            'staff_user_id' => $staffUserId,
            'action_type' => 'check_in',
        ]);

        $session = $session->fresh(['currentStation', 'serviceTrack']);
        $station = $session->currentStation;
        event(new StatusUpdate($session->current_station_id, $session));
        event(new NowServing($session->current_station_id, [
            'session_id' => $session->id,
            'alias' => $session->alias,
            'category' => $session->client_category,
        ]));
        event(new QueueLengthUpdated($session->current_station_id));
        event(new StationActivity(
            $session->current_station_id,
            $station?->name ?? '—',
            "{$session->alias} arrived (serving)",
            $session->alias,
            'check_in',
            now()->toIso8601String()
        ));

        return ['session' => $session];
    }

    /**
     * Per 08-API-SPEC-PHASE1 §3.2: Transfer session to next station.
     *
     * @param  'standard'|'custom'  $mode
     * @return array{session: Session, action_required?: string}|null Flow complete response
     */
    public function transfer(Session $session, string $mode, ?int $targetStationId, int $staffUserId): ?array
    {
        if ($session->status !== 'serving') {
            throw new \InvalidArgumentException('Session is not currently being served. Cannot transfer.', 409);
        }

        $previousStationId = $session->current_station_id;

        if ($mode === 'standard') {
            $next = $this->flowEngine->calculateNextStation($session);
            if (! $next) {
                return [
                    'message' => 'No next station in track. Session is ready to complete.',
                    'session' => $session->fresh(['currentStation', 'serviceTrack']),
                    'action_required' => 'complete',
                ];
            }
            $targetStationId = $next['station_id'];
            $newStepOrder = $next['step_order'];
        } else {
            $station = Station::find($targetStationId);
            if (! $station || ! $station->is_active) {
                throw new \InvalidArgumentException('Target station not found or inactive.', 422);
            }
            $step = TrackStep::where('track_id', $session->track_id)->where('station_id', $targetStationId)->first();
            $newStepOrder = $step?->step_order ?? $session->current_step_order;
        }

        return DB::transaction(function () use ($session, $previousStationId, $targetStationId, $newStepOrder, $staffUserId) {
            $nextPos = $this->getNextQueuePositionAtStation($targetStationId);
            $session->update([
                'current_station_id' => $targetStationId,
                'current_step_order' => $newStepOrder,
                'station_queue_position' => $nextPos,
                'status' => 'waiting',
                'queued_at_station' => now(),
                'no_show_attempts' => 0,
            ]);

            TransactionLog::create([
                'session_id' => $session->id,
                'station_id' => $previousStationId,
                'staff_user_id' => $staffUserId,
                'action_type' => 'transfer',
                'previous_station_id' => $previousStationId,
                'next_station_id' => $targetStationId,
            ]);

            $session = $session->fresh(['currentStation', 'serviceTrack', 'token']);
            $prevStation = \App\Models\Station::find($previousStationId);

            event(new StatusUpdate($previousStationId, $session));
            event(new ClientArrived($session, $targetStationId));
            event(new NowServing($targetStationId, [
                'session_id' => $session->id,
                'alias' => $session->alias,
                'category' => $session->client_category,
            ]));
            event(new QueueLengthUpdated($previousStationId));
            event(new QueueLengthUpdated($targetStationId));

            return [
                'session' => $session,
                'previous_station' => $prevStation ? ['id' => $prevStation->id, 'name' => $prevStation->name] : null,
            ];
        });
    }

    /**
     * Per 08-API-SPEC-PHASE1 §3.4: Complete session at final station.
     *
     * @return array{session: Session, token: array}
     */
    public function complete(Session $session, int $staffUserId): array
    {
        if ($session->status !== 'serving') {
            throw new \InvalidArgumentException('Session is not being served. Call next first.', 409);
        }

        $maxRequiredStep = TrackStep::where('track_id', $session->track_id)->where('is_required', true)->max('step_order');
        if ($maxRequiredStep !== null && $session->current_step_order < $maxRequiredStep) {
            $remaining = TrackStep::where('track_id', $session->track_id)
                ->where('is_required', true)
                ->where('step_order', '>', $session->current_step_order)
                ->orderBy('step_order')
                ->with('station')
                ->get()
                ->map(fn ($s) => ['step_order' => $s->step_order, 'station' => $s->station->name, 'is_required' => true])
                ->values()
                ->toArray();
            throw new StepsRemainingException('Cannot complete: required steps remaining.', $remaining);
        }

        return $this->finishSession($session, $staffUserId, 'complete');
    }

    /**
     * Per 08-API-SPEC-PHASE1 §3.5: Cancel session.
     *
     * @return array{session: Session, token: array}
     */
    public function cancel(Session $session, int $staffUserId, ?string $remarks = null): array
    {
        if (! in_array($session->status, ['waiting', 'called', 'serving'], true)) {
            throw new \InvalidArgumentException('Session is already completed.', 409);
        }

        return $this->finishSession($session, $staffUserId, 'cancel', $remarks);
    }

    /**
     * Per plan: Mark no-show. From 'called' (or 'waiting').
     * Increments no_show_attempts. If >= 3, terminates session. Else, returns to waiting.
     *
     * @return array{session: Session, token?: array, back_to_waiting?: bool}
     */
    public function markNoShow(Session $session, int $staffUserId): array
    {
        if (! in_array($session->status, ['waiting', 'called'], true)) {
            throw new \InvalidArgumentException('No-show only applies to called or waiting clients. Use Cancel for serving.', 409);
        }

        $session->increment('no_show_attempts');
        $attempts = $session->fresh()->no_show_attempts;

        if ($attempts >= 3) {
            return $this->finishSession($session, $staffUserId, 'no_show');
        }

        return DB::transaction(function () use ($session, $staffUserId) {
            $stationId = $session->current_station_id;
            $session->update(['status' => 'waiting']);

            TransactionLog::create([
                'session_id' => $session->id,
                'station_id' => $stationId,
                'staff_user_id' => $staffUserId,
                'action_type' => 'no_show',
                'metadata' => ['back_to_waiting' => true, 'attempt' => $session->no_show_attempts],
            ]);

            $session = $session->fresh(['currentStation', 'serviceTrack']);
            if ($stationId) {
                event(new StatusUpdate($stationId, $session));
                event(new QueueLengthUpdated($stationId));
            }

            return [
                'session' => $session,
                'back_to_waiting' => true,
                'no_show_attempts' => $session->no_show_attempts,
            ];
        });
    }

    /**
     * Per 08-API-SPEC-PHASE1 §3.8: Force-complete (supervisor only). Caller must validate auth first.
     *
     * @return array{session: Session, token: array}
     */
    public function forceComplete(Session $session, string $reason, int $supervisorUserId, int $staffUserId): array
    {
        if (! in_array($session->status, ['waiting', 'called', 'serving'], true)) {
            throw new \InvalidArgumentException('Session is already completed.', 409);
        }

        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Reason is required for force-complete.', 422);
        }

        return DB::transaction(function () use ($session, $staffUserId, $reason, $supervisorUserId) {
            $result = $this->finishSession($session, $staffUserId, 'force_complete', $reason, ['supervisor_id' => $supervisorUserId]);

            return $result;
        });
    }

    /**
     * Per 08-API-SPEC-PHASE1 §3.3: Override (supervisor route deviation). Caller must validate auth first.
     *
     * @return array{session: Session, override: array}
     */
    public function override(Session $session, int $targetStationId, string $reason, int $supervisorUserId, int $staffUserId): array
    {
        if (! in_array($session->status, ['waiting', 'called', 'serving'], true)) {
            throw new \InvalidArgumentException('Session is not in a valid state for override.', 409);
        }

        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Reason is required for overrides.', 422);
        }

        $station = Station::find($targetStationId);
        if (! $station || ! $station->is_active) {
            throw new \InvalidArgumentException('Target station not found or inactive.', 422);
        }

        $previousStationId = $session->current_station_id;
        $step = TrackStep::where('track_id', $session->track_id)->where('station_id', $targetStationId)->first();
        $newStepOrder = $step?->step_order ?? $session->current_step_order;

        return DB::transaction(function () use ($session, $targetStationId, $reason, $supervisorUserId, $staffUserId, $previousStationId, $newStepOrder) {
            $nextPos = $this->getNextQueuePositionAtStation($targetStationId);
            $session->update([
                'current_station_id' => $targetStationId,
                'current_step_order' => $newStepOrder,
                'station_queue_position' => $nextPos,
                'status' => 'waiting',
                'queued_at_station' => now(),
                'no_show_attempts' => 0,
            ]);

            TransactionLog::create([
                'session_id' => $session->id,
                'station_id' => $previousStationId,
                'staff_user_id' => $staffUserId,
                'action_type' => 'override',
                'previous_station_id' => $previousStationId,
                'next_station_id' => $targetStationId,
                'remarks' => $reason,
                'metadata' => ['supervisor_id' => $supervisorUserId],
            ]);

            $session = $session->fresh(['currentStation', 'serviceTrack']);
            $supervisor = \App\Models\User::find($supervisorUserId);

            event(new StatusUpdate($previousStationId, $session));
            event(new ClientArrived($session, $targetStationId));
            event(new QueueLengthUpdated($previousStationId));
            event(new QueueLengthUpdated($targetStationId));

            return [
                'session' => $session,
                'override' => [
                    'authorized_by' => $supervisor ? $supervisor->name.' ('.ucfirst($supervisor->role->value).')' : 'Unknown',
                    'reason' => $reason,
                ],
            ];
        });
    }

    /**
     * Shared logic to terminate session and unbind token.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{session: Session, token: array}
     */
    private function finishSession(Session $session, int $staffUserId, string $actionType, ?string $remarks = null, array $metadata = []): array
    {
        return DB::transaction(function () use ($session, $staffUserId, $actionType, $remarks, $metadata) {
            $token = $session->token;
            $stationId = $session->current_station_id;

            $session->update([
                'status' => $actionType === 'cancel' ? 'cancelled' : ($actionType === 'no_show' ? 'no_show' : 'completed'),
                'completed_at' => now(),
                'current_station_id' => null,
            ]);

            $token->update([
                'status' => 'available',
                'current_session_id' => null,
            ]);

            TransactionLog::create(array_merge([
                'session_id' => $session->id,
                'station_id' => $stationId,
                'staff_user_id' => $staffUserId,
                'action_type' => $actionType,
            ], $remarks ? ['remarks' => $remarks] : [], $metadata ? ['metadata' => $metadata] : []));

            $session = $session->fresh();
            if ($stationId) {
                event(new StatusUpdate($stationId, $session));
                event(new QueueLengthUpdated($stationId));
            }

            return [
                'session' => $session,
                'token' => [
                    'physical_id' => $token->physical_id,
                    'status' => 'available',
                ],
            ];
        });
    }

    private function getNextQueuePositionAtStation(int $stationId): int
    {
        $max = Session::query()
            ->where('current_station_id', $stationId)
            ->whereIn('status', ['waiting', 'called', 'serving'])
            ->max('station_queue_position');

        return ($max ?? 0) + 1;
    }
}
