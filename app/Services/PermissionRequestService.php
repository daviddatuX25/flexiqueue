<?php

namespace App\Services;

use App\Events\PermissionRequestResponded;
use App\Models\PermissionRequest;
use App\Models\Session;

/**
 * Handles permission request lifecycle: create, approve, reject.
 */
class PermissionRequestService
{
    public function __construct(
        private SessionService $sessionService
    ) {}

    /**
     * Create permission request. When action is override, detaches session from queues (awaiting_approval).
     */
    public function create(Session $session, string $actionType, int $requesterUserId, string $reason, ?int $targetStationId = null, ?int $targetTrackId = null, ?array $customSteps = null): PermissionRequest
    {
        $pr = PermissionRequest::create([
            'session_id' => $session->id,
            'action_type' => $actionType,
            'requester_user_id' => $requesterUserId,
            'status' => PermissionRequest::STATUS_PENDING,
            'target_station_id' => $targetStationId,
            'target_track_id' => $targetTrackId,
            'custom_steps' => $customSteps,
            'reason' => $reason,
        ]);

        if ($actionType === PermissionRequest::ACTION_OVERRIDE) {
            $session->update([
                'status' => 'awaiting_approval',
                'current_station_id' => null,
            ]);
        }

        return $pr;
    }

    /**
     * Approve and execute the override or force-complete.
     *
     * @return array{session: array, token?: array, previous_station?: array}
     */
    public function approve(PermissionRequest $pr, int $responderUserId): array
    {
        if (! $pr->isPending()) {
            throw new \InvalidArgumentException('Request is no longer pending.', 409);
        }

        $pr->update([
            'status' => PermissionRequest::STATUS_APPROVED,
            'responded_by_user_id' => $responderUserId,
            'responded_at' => now(),
        ]);

        broadcast(new PermissionRequestResponded($pr->fresh()))->toOthers();

        $session = $pr->session->fresh();

        if ($pr->action_type === PermissionRequest::ACTION_OVERRIDE) {
            if ($pr->target_track_id !== null) {
                $result = $this->sessionService->overrideByTrack(
                    $session,
                    (int) $pr->target_track_id,
                    $pr->reason,
                    $responderUserId,
                    $pr->requester_user_id,
                    $pr->custom_steps
                );
            } elseif ($pr->target_station_id !== null) {
                $result = $this->sessionService->override(
                    $session,
                    (int) $pr->target_station_id,
                    $pr->reason,
                    $responderUserId,
                    $pr->requester_user_id
                );
            } else {
                throw new \InvalidArgumentException('Override request has no target (track or station).', 422);
            }

            return [
                'session' => $this->formatSession($result['session']),
                'override' => $result['override'] ?? null,
            ];
        }

        $result = $this->sessionService->forceComplete(
            $session,
            $pr->reason,
            $responderUserId,
            $pr->requester_user_id
        );

        return [
            'session' => $this->formatSession($result['session']),
            'token' => $result['token'],
        ];
    }

    public function reject(PermissionRequest $pr, int $responderUserId): void
    {
        if (! $pr->isPending()) {
            throw new \InvalidArgumentException('Request is no longer pending.', 409);
        }

        $pr->update([
            'status' => PermissionRequest::STATUS_REJECTED,
            'responded_by_user_id' => $responderUserId,
            'responded_at' => now(),
        ]);

        broadcast(new PermissionRequestResponded($pr->fresh()))->toOthers();
    }

    private function formatSession($session): array
    {
        $session->loadMissing(['currentStation', 'serviceTrack']);

        return [
            'id' => $session->id,
            'alias' => $session->alias,
            'status' => $session->status,
            'current_station' => $session->currentStation ? [
                'id' => $session->currentStation->id,
                'name' => $session->currentStation->name,
            ] : null,
            'track' => $session->serviceTrack ? [
                'id' => $session->serviceTrack->id,
                'name' => $session->serviceTrack->name,
            ] : null,
        ];
    }
}
