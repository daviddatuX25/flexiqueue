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
use App\Models\ServiceTrack;
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
        private PinService $pinService,
        private StationSelectionService $stationSelectionService,
        private IdentityBindingService $identityBindingService,
    ) {}

    /**
     * Bind token to a new session. Throws domain exceptions for 400/409 cases.
     * When $staffUserId is null (public self-serve), transaction_log records null for audit.
     *
     * @return array{session: \App\Models\Session, token: array}
     */
    public function bind(string $qrHash, int $trackId, ?string $clientCategory, ?int $staffUserId = null, ?array $clientBindingPayload = null, ?string $bindingSource = null): array
    {
        $program = Program::where('is_active', true)->first();
        if (! $program) {
            throw new \InvalidArgumentException('No active program. Please activate a program first.');
        }

        $settings = $program->settings();
        $isPublic = $staffUserId === null;
        $bindingMode = $settings->getIdentityBindingMode();
        $bindingRequired = $isPublic ? $settings->requiresPublicBinding() : $settings->isBindingRequired();
        $bindingAllowed = $isPublic ? $settings->allowsPublicBinding() : true;
        $bindingSource = $bindingSource ?? ($isPublic ? 'public_triage' : 'staff_triage');

        $token = Token::where('qr_code_hash', $qrHash)->first();
        if (! $token) {
            throw new \InvalidArgumentException('Token not found.', 422);
        }

        if ($token->status === 'in_use') {
            $session = $token->currentSession;
            $session->load('currentStation');
            throw new TokenInUseException($session);
        }

        if ($token->status === 'deactivated') {
            throw new \InvalidArgumentException('Token is deactivated and cannot be used.', 422);
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

        $firstStationId = $this->resolveFirstStationForStep($firstStep, $program->id);
        if ($firstStationId === null) {
            throw new \InvalidArgumentException('Track first step has no stations.', 422);
        }
        $bindingResult = $this->identityBindingService->resolve(
            $clientBindingPayload,
            $bindingAllowed,
            $bindingRequired,
            $bindingSource,
            $bindingMode
        );

        return DB::transaction(function () use ($token, $program, $track, $firstStep, $firstStationId, $clientCategory, $staffUserId, $bindingResult) {
            $clientCategory = ClientCategory::normalize($clientCategory) ?? $clientCategory;
            $nextPos = $this->getNextQueuePositionAtStation($firstStationId);
            $session = \App\Models\Session::create([
                'token_id' => $token->id,
                'client_id' => $bindingResult['client_id'],
                'program_id' => $program->id,
                'track_id' => $track->id,
                'alias' => $token->physical_id,
                'client_category' => $clientCategory,
                'current_station_id' => $firstStationId,
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
                'staff_user_id' => $staffUserId, // null for public self-serve
                'action_type' => 'bind',
                'next_station_id' => $firstStationId,
                'created_at' => now(),
            ]);

            if ($bindingResult['client_id'] !== null && $bindingResult['metadata'] !== null) {
                TransactionLog::create([
                    'session_id' => $session->id,
                    'station_id' => null,
                    'staff_user_id' => $staffUserId,
                    'action_type' => 'identity_bind',
                    'metadata' => $bindingResult['metadata'],
                    'created_at' => now(),
                ]);
            }

            event(new ClientArrived($session->fresh(['currentStation', 'serviceTrack']), $firstStationId));
            event(new QueueLengthUpdated($firstStationId));

            // Per ISSUES-ELABORATION §10: broadcast so display board shows triage bind in real time
            $firstStation = Station::find($firstStationId);
            event(new StationActivity(
                $firstStationId,
                $firstStation?->name ?? 'Triage',
                "{$session->alias} registered at triage",
                $session->alias,
                'bind',
                now()->toIso8601String(),
                $token->pronounce_as ?? 'letters',
                $session->token_id
            ));

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

        return DB::transaction(function () use ($session, $station, $staffUserId) {
            // Robustness: serialize capacity-affecting transitions per station to avoid oversubscription races.
            Station::query()->whereKey($station->id)->lockForUpdate()->first();

            $clientCapacity = (int) ($station->client_capacity ?? 1);
            $currentCount = Session::query()
                ->capacityConsumingAtStation($station->id)
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
                'created_at' => now(),
            ]);

            $session = $session->fresh(['serviceTrack', 'currentStation', 'token']);
            $station = $session->currentStation;
            // Only indicate "priority lane" when the client has a priority classification (PWD/Senior/Pregnant), not when the program is merely priority-first.
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
                now()->toIso8601String(),
                $session->token?->pronounce_as ?? 'letters',
                $session->token_id
            ));

            return [
                'session_id' => $session->id,
                'alias' => $session->alias,
                'no_show_attempts' => $session->no_show_attempts ?? 0,
                'status' => 'called',
            ];
        });

    }

    /**
     * Whether calling this session would require supervisor auth (skipping priority when program has require_permission_before_override).
     * Per REFACTORING-ISSUE-LIST Issue 6.
     */
    public function callRequiresOverrideAuth(Session $session): bool
    {
        if ($session->status !== 'waiting') {
            return false;
        }

        $station = Station::find($session->current_station_id);
        if (! $station) {
            return false;
        }

        $station->loadMissing('program');
        $program = $station->program;
        if (! $program || ! $program->settings()->getRequirePermissionBeforeOverride()) {
            return false;
        }

        $priorityFirst = $station->priority_first_override !== null
            ? (bool) $station->priority_first_override
            : $program->settings()->getPriorityFirst();
        if ($priorityFirst) {
            return false;
        }

        if (ClientCategory::isPriority($session->client_category)) {
            return false;
        }

        $priorityWaitingCount = Session::query()
            ->where('current_station_id', $station->id)
            ->where('status', 'waiting')
            ->where('id', '!=', $session->id)
            ->get()
            ->filter(fn (Session $s) => $s->isPriorityCategory())
            ->count();

        return $priorityWaitingCount > 0;
    }

    /**
     * Per plan: Serve session — client physically showed, staff clicks Serve.
     * From 'called' or 'waiting'. When 'waiting', staff's station_id required and session must be at that station; capacity enforced.
     * Logs check_in for both.
     *
     * @param  int|null  $stationId  Required when status is 'waiting' (staff's station); optional when 'called'.
     * @return array{session: Session}
     */
    public function serve(Session $session, int $staffUserId, ?int $stationId = null): array
    {
        if (! in_array($session->status, ['called', 'waiting'], true)) {
            throw new \InvalidArgumentException('Session is not in called or waiting state. Call the client first or start serving from waiting.', 409);
        }

        return DB::transaction(function () use ($session, $staffUserId, $stationId) {
            $station = $session->currentStation;
            if (! $station) {
                throw new \InvalidArgumentException('Session has no current station.', 409);
            }

            // Robustness: serialize capacity-affecting transitions per station to avoid oversubscription races.
            Station::query()->whereKey($station->id)->lockForUpdate()->first();

            if ($session->status === 'waiting') {
                if ($stationId === null) {
                    throw new \InvalidArgumentException('Station context is required when serving from waiting.', 422);
                }
                if ($session->current_station_id !== $stationId) {
                    throw new \InvalidArgumentException('Session is not at this station.', 409);
                }

                $clientCapacity = (int) ($station->client_capacity ?? 1);
                $currentCount = Session::query()
                    ->capacityConsumingAtStation($station->id)
                    ->count();
                if ($currentCount >= $clientCapacity) {
                    throw new \InvalidArgumentException("Station at capacity ({$clientCapacity}). Cannot start serving more clients.", 409);
                }
            }

            $session->update(['status' => 'serving']);

            TransactionLog::create([
                'session_id' => $session->id,
                'station_id' => $session->current_station_id,
                'staff_user_id' => $staffUserId,
                'action_type' => 'check_in',
                'created_at' => now(),
            ]);

            $session = $session->fresh(['currentStation', 'serviceTrack', 'token']);
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
                now()->toIso8601String(),
                $session->token?->pronounce_as ?? 'letters',
                $session->token_id
            ));

            return ['session' => $session];
        });
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
                    'message' => 'No next process in track. Session is ready to complete.',
                    'session' => $session->fresh(['currentStation', 'serviceTrack']),
                    'action_required' => 'complete',
                ];
            }
            $targetStationId = $this->resolveTargetStationFromNext($next, $session->program_id);
            if ($targetStationId === null) {
                return [
                    'message' => 'No next process available.',
                    'session' => $session->fresh(['currentStation', 'serviceTrack']),
                    'action_required' => 'override',
                ];
            }
            $newStepOrder = $next['step_order'];
        } else {
            $station = Station::find($targetStationId);
            if (! $station || ! $station->is_active) {
                throw new \InvalidArgumentException('Target station not found or inactive.', 422);
            }
            $newStepOrder = $this->resolveStepOrderForTarget($session, $targetStationId);
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
                'created_at' => now(),
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

        $maxRequiredStep = $this->getMaxRequiredStepOrder($session);
        if ($maxRequiredStep !== null && $session->current_step_order < $maxRequiredStep) {
            $remaining = $this->getRemainingRequiredSteps($session);
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
     * Move a serving session into this station's holding area. Per station-holding-area plan.
     */
    public function moveToHolding(Session $session, Station $station, int $staffUserId, ?string $remarks = null): void
    {
        if ($session->status !== 'serving') {
            throw new \InvalidArgumentException('Session is not being served. Only serving sessions can be moved to holding.', 409);
        }
        if ($session->current_station_id !== $station->id) {
            throw new \InvalidArgumentException('Session is not at this station.', 409);
        }
        if ($session->isOnHold()) {
            throw new \InvalidArgumentException('Session is already on hold.', 409);
        }

        $heldCount = Session::query()
            ->where('holding_station_id', $station->id)
            ->where('is_on_hold', true)
            ->count();
        if ($heldCount >= $station->getHoldingCapacity()) {
            throw new \InvalidArgumentException('Holding area is full. Resume a client from hold first.', 422);
        }

        DB::transaction(function () use ($session, $station, $staffUserId, $remarks) {
            $nextOrder = (int) Session::query()
                ->where('holding_station_id', $station->id)
                ->max('held_order');
            $nextOrder = $nextOrder + 1;

            $session->update([
                'is_on_hold' => true,
                'holding_station_id' => $station->id,
                'held_at' => now(),
                'held_order' => $nextOrder,
            ]);

            TransactionLog::create([
                'session_id' => $session->id,
                'station_id' => $station->id,
                'staff_user_id' => $staffUserId,
                'action_type' => 'hold',
                'remarks' => $remarks,
                'created_at' => now(),
            ]);

            $session = $session->fresh(['currentStation', 'serviceTrack', 'token']);
            event(new StatusUpdate($station->id, $session));
            event(new QueueLengthUpdated($station->id));
        });
    }

    /**
     * Resume a session from this station's holding area back to serving. Per station-holding-area plan.
     */
    public function resumeFromHolding(Session $session, Station $station, int $staffUserId, ?string $remarks = null): void
    {
        if (! $session->isOnHold() || $session->holding_station_id !== $station->id) {
            throw new \InvalidArgumentException('Session is not on hold at this station.', 409);
        }

        DB::transaction(function () use ($session, $station, $staffUserId, $remarks) {
            // Robustness: serialize capacity-affecting transitions per station to avoid oversubscription races.
            Station::query()->whereKey($station->id)->lockForUpdate()->first();

            $clientCapacity = (int) ($station->client_capacity ?? 1);
            $currentServingCount = Session::query()
                ->capacityConsumingAtStation($station->id)
                ->count();
            if ($currentServingCount >= $clientCapacity) {
                throw new \InvalidArgumentException("Station at capacity ({$clientCapacity}). Complete or transfer a client first.", 422);
            }

            $session->update([
                'is_on_hold' => false,
                'holding_station_id' => null,
                'held_at' => null,
                'held_order' => null,
                'status' => 'serving',
            ]);

            TransactionLog::create([
                'session_id' => $session->id,
                'station_id' => $station->id,
                'staff_user_id' => $staffUserId,
                'action_type' => 'resume_from_hold',
                'remarks' => $remarks,
                'created_at' => now(),
            ]);

            $session = $session->fresh(['currentStation', 'serviceTrack', 'token']);
            event(new StatusUpdate($station->id, $session));
            event(new QueueLengthUpdated($station->id));
            event(new NowServing($station->id, [
                'session_id' => $session->id,
                'alias' => $session->alias,
                'category' => $session->client_category,
            ]));
        });
    }

    /**
     * Per flexiqueue-a3wh: Enqueue session back to same station at end of queue. From 'called' or 'serving'.
     * Preserves no_show_attempts. Clears hold state if present.
     *
     * @return array{session: Session, back_to_waiting: bool}
     */
    public function enqueueBack(Session $session, int $staffUserId): array
    {
        if (! in_array($session->status, ['called', 'serving'], true)) {
            throw new \InvalidArgumentException('Enqueue back only applies to called or serving sessions.', 409);
        }

        $stationId = $session->current_station_id;
        if (! $stationId) {
            throw new \InvalidArgumentException('Session has no current station.', 409);
        }

        $wasServing = $session->status === 'serving';

        return DB::transaction(function () use ($session, $staffUserId, $stationId, $wasServing) {
            $nextPos = $this->getNextQueuePositionAtStation($stationId);
            $session->update([
                'status' => 'waiting',
                'station_queue_position' => $nextPos,
                'queued_at_station' => now(),
                'is_on_hold' => false,
                'holding_station_id' => null,
                'held_at' => null,
                'held_order' => null,
            ]);

            TransactionLog::create([
                'session_id' => $session->id,
                'station_id' => $stationId,
                'staff_user_id' => $staffUserId,
                'action_type' => 'enqueue_back',
                'created_at' => now(),
            ]);

            $session = $session->fresh(['currentStation', 'serviceTrack', 'token']);
            event(new StatusUpdate($stationId, $session));
            event(new QueueLengthUpdated($stationId));
            if ($wasServing) {
                event(new NowServing($stationId, [
                    'session_id' => $session->id,
                    'alias' => $session->alias,
                    'category' => $session->client_category,
                ]));
            }

            return [
                'session' => $session,
                'back_to_waiting' => true,
            ];
        });
    }

    /**
     * Per flexiqueue-a3wh: Mark no-show. From 'called', 'waiting', or 'serving'.
     * Optional enqueue_back (move to end vs stay at front). At max attempts, staff must choose extend or last_call.
     *
     * @return array{session: Session, token?: array, back_to_waiting?: bool, no_show_attempts?: int, extended?: bool}
     */
    public function markNoShow(Session $session, int $staffUserId, bool $enqueueBack = false, bool $extend = false, bool $lastCall = false): array
    {
        if (! in_array($session->status, ['waiting', 'called', 'serving'], true)) {
            throw new \InvalidArgumentException('No-show only applies to waiting, called, or serving sessions.', 409);
        }

        $program = Program::find($session->program_id);
        $max = $program?->settings()->getMaxNoShowAttempts() ?? 3;
        $attempts = (int) $session->no_show_attempts;

        if ($lastCall) {
            return $this->finishSession($session, $staffUserId, 'no_show');
        }

        if ($extend) {
            $session->increment('no_show_attempts');
            $session->refresh();
            return $this->applyNoShowBackToWaiting($session, $staffUserId, $enqueueBack, true);
        }

        if ($attempts < $max) {
            $session->increment('no_show_attempts');
            $session->refresh();
            return $this->applyNoShowBackToWaiting($session, $staffUserId, $enqueueBack, false);
        }

        throw new \InvalidArgumentException('At max no-show attempts. Use extend or last_call.', 422);
    }

    /**
     * Apply back-to-waiting state after no-show (increment already done by caller). Optionally move to end of queue.
     */
    private function applyNoShowBackToWaiting(Session $session, int $staffUserId, bool $enqueueBack, bool $extended): array
    {
        $stationId = $session->current_station_id;
        $updates = ['status' => 'waiting'];
        if ($enqueueBack && $stationId) {
            $updates['station_queue_position'] = $this->getNextQueuePositionAtStation($stationId);
            $updates['queued_at_station'] = now();
        }

        return DB::transaction(function () use ($session, $staffUserId, $stationId, $updates, $enqueueBack, $extended) {
            $session->update($updates);

            $metadata = [
                'back_to_waiting' => true,
                'attempt' => $session->no_show_attempts,
            ];
            if ($enqueueBack) {
                $metadata['enqueue_back'] = true;
            }
            if ($extended) {
                $metadata['extended'] = true;
            }

            TransactionLog::create([
                'session_id' => $session->id,
                'station_id' => $stationId,
                'staff_user_id' => $staffUserId,
                'action_type' => 'no_show',
                'metadata' => $metadata,
                'created_at' => now(),
            ]);

            $session = $session->fresh(['currentStation', 'serviceTrack']);
            if ($stationId) {
                event(new StatusUpdate($stationId, $session));
                event(new QueueLengthUpdated($stationId));
            }

            $result = [
                'session' => $session,
                'back_to_waiting' => true,
                'no_show_attempts' => $session->no_show_attempts,
            ];
            if ($extended) {
                $result['extended'] = true;
            }

            return $result;
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
     * @deprecated Use overrideByTrack() with customSteps instead. TODO: remove after PermissionRequestService migration.
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
                'created_at' => now(),
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
                'is_on_hold' => false,
                'holding_station_id' => null,
                'held_at' => null,
                'held_order' => null,
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
                'created_at' => now(),
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

    /**
     * Per TRACK-OVERRIDES-REFACTOR: Override by track or custom path.
     * If customSteps provided: set session.override_steps, move to first station. Else: move to first station of track, update track_id.
     *
     * @param  array<int>|null  $customSteps
     * @return array{session: Session, override: array}
     */
    public function overrideByTrack(Session $session, int $targetTrackId, string $reason, int $supervisorUserId, int $staffUserId, ?array $customSteps = null): array
    {
        if (! in_array($session->status, ['waiting', 'called', 'serving', 'awaiting_approval'], true)) {
            throw new \InvalidArgumentException('Session is not in a valid state for override.', 409);
        }

        // Per flexiqueue-eiju: reason required only for custom path, not predefined track
        if (($customSteps !== null && count($customSteps) > 0) && trim($reason) === '') {
            throw new \InvalidArgumentException('Reason is required for overrides.', 422);
        }

        $program = $session->program;
        if (! $program) {
            throw new \InvalidArgumentException('Session has no program.', 422);
        }

        $previousStationId = $session->current_station_id;

        // Per TRACK-OVERRIDES-REFACTOR: custom path skips track validation
        if ($customSteps !== null && count($customSteps) > 0) {
            return $this->reassignToCustomPath($session, $customSteps, $staffUserId, $reason, $supervisorUserId, $previousStationId);
        }

        $track = ServiceTrack::where('program_id', $program->id)->find($targetTrackId);
        if (! $track) {
            throw new \InvalidArgumentException('Target track not found or does not belong to session program.', 422);
        }

        $firstStep = $track->trackSteps()->where('step_order', 1)->first();
        if (! $firstStep) {
            throw new \InvalidArgumentException('Target track has no steps defined.', 422);
        }

        $targetStationId = $this->resolveFirstStationForStep($firstStep, $program->id);
        if ($targetStationId === null) {
            throw new \InvalidArgumentException('Target station not found or inactive.', 422);
        }

        return DB::transaction(function () use ($session, $targetStationId, $targetTrackId, $reason, $supervisorUserId, $staffUserId, $previousStationId) {
            $nextPos = $this->getNextQueuePositionAtStation($targetStationId);
            $session->update([
                'track_id' => $targetTrackId,
                'current_station_id' => $targetStationId,
                'current_step_order' => 1,
                'override_steps' => null,
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
                'created_at' => now(),
            ]);

            $session = $session->fresh(['currentStation', 'serviceTrack']);
            $supervisor = \App\Models\User::find($supervisorUserId);

            if ($previousStationId !== null) {
                event(new StatusUpdate($previousStationId, $session));
                event(new QueueLengthUpdated($previousStationId));
            }
            event(new ClientArrived($session, $targetStationId));
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
     * Per TRACK-OVERRIDES-REFACTOR: Reassign session to track (reject flow).
     *
     * @return array{session: Session}
     */
    public function reassignToTrack(Session $session, int $trackId, int $staffUserId): array
    {
        if ($session->status !== 'awaiting_approval') {
            throw new \InvalidArgumentException('Session must be awaiting approval to reassign.', 409);
        }

        $track = ServiceTrack::where('program_id', $session->program_id)->find($trackId);
        if (! $track) {
            throw new \InvalidArgumentException('Target track not found.', 422);
        }

        $firstStep = $track->trackSteps()->where('step_order', 1)->first();
        if (! $firstStep) {
            throw new \InvalidArgumentException('Target track has no steps.', 422);
        }

        $firstStationId = $this->resolveFirstStationForStep($firstStep, $session->program_id);
        if ($firstStationId === null) {
            throw new \InvalidArgumentException('First station of track not found or inactive.', 422);
        }

        return DB::transaction(function () use ($session, $track, $firstStationId, $staffUserId) {
            $nextPos = $this->getNextQueuePositionAtStation($firstStationId);
            $session->update([
                'track_id' => $track->id,
                'current_station_id' => $firstStationId,
                'current_step_order' => 1,
                'override_steps' => null,
                'station_queue_position' => $nextPos,
                'status' => 'waiting',
                'queued_at_station' => now(),
            ]);

            event(new ClientArrived($session->fresh(['currentStation', 'serviceTrack']), $firstStationId));
            event(new QueueLengthUpdated($firstStationId));

            return ['session' => $session->fresh()];
        });
    }

    /**
     * Per TRACK-OVERRIDES-REFACTOR: Reassign session to custom path (reject flow).
     *
     * @param  array<int>  $stationIds
     * @return array{session: Session}
     */
    public function reassignToCustomPath(Session $session, array $stationIds, int $staffUserId, ?string $reason = null, ?int $supervisorUserId = null, ?int $previousStationId = null): array
    {
        if (count($stationIds) === 0) {
            throw new \InvalidArgumentException('Custom path must have at least one station.', 422);
        }

        $firstStationId = (int) $stationIds[0];
        $station = Station::find($firstStationId);
        if (! $station || ! $station->is_active) {
            throw new \InvalidArgumentException('First station in path not found or inactive.', 422);
        }

        $prevId = $previousStationId ?? $session->current_station_id;

        return DB::transaction(function () use ($session, $stationIds, $firstStationId, $staffUserId, $reason, $supervisorUserId, $prevId) {
            $nextPos = $this->getNextQueuePositionAtStation($firstStationId);
            $session->update([
                'current_station_id' => $firstStationId,
                'current_step_order' => 1,
                'override_steps' => $stationIds,
                'station_queue_position' => $nextPos,
                'status' => 'waiting',
                'queued_at_station' => now(),
                'no_show_attempts' => 0,
            ]);

            if ($reason && $supervisorUserId) {
                TransactionLog::create([
                    'session_id' => $session->id,
                    'station_id' => $prevId,
                    'staff_user_id' => $staffUserId,
                    'action_type' => 'override',
                    'previous_station_id' => $prevId,
                    'next_station_id' => $firstStationId,
                    'remarks' => $reason,
                    'metadata' => ['supervisor_id' => $supervisorUserId],
                    'created_at' => now(),
                ]);
            }

            $session = $session->fresh(['currentStation', 'serviceTrack']);
            $supervisor = \App\Models\User::find($supervisorUserId ?? 0);

            if ($prevId !== null) {
                event(new StatusUpdate($prevId, $session));
                event(new QueueLengthUpdated($prevId));
            }
            event(new ClientArrived($session, $firstStationId));
            event(new QueueLengthUpdated($firstStationId));

            return [
                'session' => $session,
                'override' => $reason ? [
                    'authorized_by' => $supervisor ? $supervisor->name.' ('.ucfirst($supervisor->role->value).')' : 'Unknown',
                    'reason' => $reason,
                ] : null,
            ];
        });
    }

    /**
     * Max required step order for completion. When override_steps set, all steps required.
     */
    private function getMaxRequiredStepOrder(Session $session): ?int
    {
        $overrideSteps = $session->override_steps;
        if (is_array($overrideSteps) && count($overrideSteps) > 0) {
            return count($overrideSteps);
        }

        return TrackStep::where('track_id', $session->track_id)->where('is_required', true)->max('step_order');
    }

    /**
     * @return array<int, array{step_order: int, station: string, is_required: bool}>
     */
    private function getRemainingRequiredSteps(Session $session): array
    {
        $overrideSteps = $session->override_steps;
        if (is_array($overrideSteps) && count($overrideSteps) > 0) {
            $remaining = [];
            $currentOrder = (int) ($session->current_step_order ?? 1);
            for ($i = $currentOrder; $i < count($overrideSteps); $i++) {
                $station = Station::find($overrideSteps[$i]);
                $remaining[] = [
                    'step_order' => $i + 1,
                    'station' => $station?->name ?? 'Unknown',
                    'is_required' => true,
                ];
            }

            return $remaining;
        }

        return TrackStep::where('track_id', $session->track_id)
            ->where('is_required', true)
            ->where('step_order', '>', $session->current_step_order)
            ->orderBy('step_order')
            ->with('process')
            ->get()
            ->map(fn ($s) => ['step_order' => $s->step_order, 'station' => $s->process?->name ?? '—', 'is_required' => true])
            ->values()
            ->toArray();
    }

    /**
     * Resolve step order when transferring to target station (custom mode).
     * Per PROCESS-STATION-REFACTOR: also match by process (station serves process).
     */
    private function resolveStepOrderForTarget(Session $session, int $targetStationId): int
    {
        $overrideSteps = $session->override_steps;
        if (is_array($overrideSteps)) {
            $idx = array_search($targetStationId, array_map('intval', $overrideSteps));
            if ($idx !== false) {
                return $idx + 1;
            }
        }

        $step = TrackStep::where('track_id', $session->track_id)->where('station_id', $targetStationId)->first();
        if ($step) {
            return $step->step_order;
        }

        $station = Station::find($targetStationId);
        if ($station) {
            $processIds = $station->processes()->pluck('process_id')->all();
            if (! empty($processIds)) {
                $step = TrackStep::where('track_id', $session->track_id)
                    ->whereIn('process_id', $processIds)
                    ->orderBy('step_order')
                    ->first();
                if ($step) {
                    return $step->step_order;
                }
            }
        }

        return $session->current_step_order;
    }

    /**
     * Resolve first station for a track step. Per PROCESS-STATION-REFACTOR: process_id → StationSelectionService.
     */
    private function resolveFirstStationForStep(TrackStep $step, int $programId): ?int
    {
        if ($step->process_id !== null) {
            return $this->stationSelectionService->selectStationForProcess($step->process_id, $programId);
        }

        $station = $step->station;
        if (! $station || ! $station->is_active) {
            return null;
        }

        return $station->id;
    }

    /**
     * Resolve target station from FlowEngine result. Handles process_id and station_id.
     *
     * @param  array{station_id?: int, process_id?: int, step_order: int}  $next
     */
    private function resolveTargetStationFromNext(array $next, int $programId): ?int
    {
        if (isset($next['station_id'])) {
            return $next['station_id'];
        }
        if (isset($next['process_id'])) {
            return $this->stationSelectionService->selectStationForProcess($next['process_id'], $programId);
        }

        return null;
    }
}
