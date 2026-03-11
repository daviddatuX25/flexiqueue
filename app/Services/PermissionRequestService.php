<?php

namespace App\Services;

use App\Events\PermissionRequestResponded;
use App\Http\Resources\SessionResource;
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
                return $this->approveViaCustomPath($session, $customSteps, $pr->reason, $responderUserId, $pr->requester_user_id);
            }
            if ($targetTrackId !== null) {
                return $this->approveViaTrack($session, (int) $targetTrackId, $pr->reason, $responderUserId, $pr->requester_user_id);
            }
            if ($pr->target_station_id !== null) {
                return $this->approveViaLegacyStation($session, (int) $pr->target_station_id, $pr->reason, $responderUserId, $pr->requester_user_id);
            }

            throw new \InvalidArgumentException('Override requires target track or custom path. Define path on Program Overrides page.', 422);
        } else {
            $result = $this->sessionService->forceComplete(
                $session,
                $pr->reason,
                $responderUserId,
                $pr->requester_user_id
            );

            $resultData = [
                'session' => SessionResource::make($result['session'])->resolve(),
                'token' => $result['token'],
            ];
        }

        return $resultData;
    }

    /**
     * Execute override via custom steps path (station IDs array).
     *
     * @return array{session: array, override: array|null}
     */
    private function approveViaCustomPath(Session $session, array $customSteps, string $reason, int $responderUserId, int $requesterUserId): array
    {
        $result = $this->sessionService->overrideByTrack(
            $session,
            0,
            $reason,
            $responderUserId,
            $requesterUserId,
            $customSteps
        );

        return [
            'session' => SessionResource::make($result['session'])->resolve(),
            'override' => $result['override'] ?? null,
        ];
    }

    /**
     * Execute override via target track (track ID).
     *
     * @return array{session: array, override: array|null}
     */
    private function approveViaTrack(Session $session, int $targetTrackId, string $reason, int $responderUserId, int $requesterUserId): array
    {
        $result = $this->sessionService->overrideByTrack(
            $session,
            $targetTrackId,
            $reason,
            $responderUserId,
            $requesterUserId,
            null
        );

        return [
            'session' => SessionResource::make($result['session'])->resolve(),
            'override' => $result['override'] ?? null,
        ];
    }

    /**
     * Execute override via legacy target_station_id (single station).
     *
     * @return array{session: array, override: array|null}
     */
    private function approveViaLegacyStation(Session $session, int $targetStationId, string $reason, int $responderUserId, int $requesterUserId): array
    {
        $result = $this->sessionService->overrideByTrack(
            $session,
            0,
            $reason,
            $responderUserId,
            $requesterUserId,
            [$targetStationId]
        );

        return [
            'session' => SessionResource::make($result['session'])->resolve(),
            'override' => $result['override'] ?? null,
        ];
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

}
