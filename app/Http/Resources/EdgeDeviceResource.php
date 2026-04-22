<?php

namespace App\Http\Resources;

use App\Models\EdgeDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for EdgeDevice model — used by the central admin Edge Devices panel.
 */
class EdgeDeviceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EdgeDevice $device */
        $device = $this->resource;

        return [
            'id'                      => $device->id,
            'name'                    => $device->name,
            'status'                  => $device->getStatus(),
            'runtime'                 => $device->runtime ?? 'pi',
            'sync_mode'               => $device->sync_mode,
            'supervisor_admin_access' => $device->supervisor_admin_access,
            'assigned_program_id'     => $device->assigned_program_id,
            'assigned_program_name'   => $device->assignedProgram?->name,
            'session_active'          => $device->session_active,
            'last_seen_at'            => $device->last_seen_at?->toIso8601String(),
            'last_synced_at'          => $device->last_synced_at?->toIso8601String(),
            'paired_at'               => $device->paired_at?->toIso8601String(),
            'app_version'             => $device->app_version,
            'update_status'           => $device->update_status,
        ];
    }
}