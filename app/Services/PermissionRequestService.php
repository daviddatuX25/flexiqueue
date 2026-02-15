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
     * For override: uses approveTargetTrackId/approveCustomSteps when provided, else pr's stored values.
     *
     * @return array{session: array, token?: array, previous_station?: array}
     */
    public function approve(PermissionRequest $pr, int $responderUserId, ?int $approveTargetTrackId = null, ?array $approveCustomSteps = null): array
    {
        if (! $pr->isPending()) {
            throw new \InvalidArgumentException('Request is no longer pending.', 409);
        }

        $pr->update([
            'status' => PermissionRequest::STATUS_APPROVED,
            'responded_by_user_id' => $responderUserId,
            'responded_at' => now(),
            'target_track_id' => $approveTargetTrackId ?? $pr->target_track_id,
            'custom_steps' => $approveCustomSteps !== null ? $approveCustomSteps : $pr->custom_steps,
        ]);

        broadcast(new PermissionRequestResponded($pr->fresh()))->toOthers();

        $session = $pr->session->fresh();

        if ($pr->action_type === PermissionRequest::ACTION_OVERRIDE) {
            $targetTrackId = $approveTargetTrackId ?? $pr->target_track_id;
            $customSteps = $approveCustomSteps ?? $pr->custom_steps;

            if ($customSteps !== null && count($customSteps) > 0) {
                $result = $this->sessionService->overrideByTrack(
                    $session,
                    0, // not used for custom
                    $pr->reason,
                    $responderUserId,
                    $pr->requester_user_id,
                    $customSteps
                );
            } elseif ($targetTrackId !== null) {
                $result = $this->sessionService->overrideByTrack(
                    $session,
                    (int) $targetTrackId,
                    $pr->reason,
                    $responderUserId,
                    $pr->requester_user_id,
                    null
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
                throw new \InvalidArgumentException('Override requires target track or custom path. Define path on Track Overrides page.', 422);
            }

            $resultData = [
                'session' => $this->formatSession($result['session']),
                'override' => $result['override'] ?? null,
            ];
        } else {
            $result = $this->sessionService->forceComplete(
                $session,
                $pr->reason,
                $responderUserId,
                $pr->requester_user_id
            );

            $resultData = [
                'session' => $this->formatSession($result['session']),
                'token' => $result['token'],
            ];
        }

        return $resultData;
    }

    /**
     * Reject permission request. Optionally reassign session to track or custom path.
     *
     * @return array{session: Session}|null When reassignment was performed
     */
    public function reject(PermissionRequest $pr, int $responderUserId, ?int $reassignTrackId = null, ?array $customSteps = null): ?array
    {
        if (! $pr->isPending()) {
            throw new \InvalidArgumentException('Request is no longer pending.', 409);
        }

        $session = $pr->session->fresh();
        $reassignResult = null;

        if ($pr->action_type === PermissionRequest::ACTION_OVERRIDE && $session->status === 'awaiting_approval') {
            if ($customSteps !== null && count($customSteps) > 0) {
                $reassignResult = $this->sessionService->reassignToCustomPath($session, $customSteps, $pr->requester_user_id);
            } elseif ($reassignTrackId !== null) {
                $reassignResult = $this->sessionService->reassignToTrack($session, $reassignTrackId, $pr->requester_user_id);
            }
        }

        $pr->update([
            'status' => PermissionRequest::STATUS_REJECTED,
            'responded_by_user_id' => $responderUserId,
            'responded_at' => now(),
        ]);

        broadcast(new PermissionRequestResponded($pr->fresh()))->toOthers();

        if ($reassignResult !== null) {
            return ['session' => $reassignResult['session']];
        }

        return null;
    }

    public function formatSessionForResponse($session): array
    {
        return $this->formatSession($session);
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
