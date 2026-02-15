<?php

namespace App\Events;

use App\Models\PermissionRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to requester when their permission request is approved or rejected.
 */
class PermissionRequestResponded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public PermissionRequest $permissionRequest
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->permissionRequest->requester_user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'permission_request_responded';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->permissionRequest->loadMissing(['session', 'respondedBy']);

        return [
            'id' => $this->permissionRequest->id,
            'status' => $this->permissionRequest->status,
            'session_id' => $this->permissionRequest->session_id,
            'session_alias' => $this->permissionRequest->session?->alias,
            'action_type' => $this->permissionRequest->action_type,
            'responded_at' => $this->permissionRequest->responded_at?->toIso8601String(),
            'responded_by_name' => $this->permissionRequest->respondedBy?->name,
        ];
    }
}
