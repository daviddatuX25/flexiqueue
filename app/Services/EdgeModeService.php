<?php

namespace App\Services;

use App\Models\EdgeDeviceState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Single source of truth for central vs edge mode and connectivity.
 *
 * Enforcement: No file in app/Http/ or app/Services/ (except this file) may call
 * config('app.mode') or read APP_MODE directly. All edge-mode checks must go
 * through this service. This allows Phase E (bridge layer) to replace
 * connectivity detection in one place.
 */
class EdgeModeService
{
    public function isEdge(): bool
    {
        return config('app.mode') === 'edge';
    }

    public function isCentral(): bool
    {
        return !$this->isEdge();
    }

    /**
     * Returns true when running on edge and the central server responds to a ping.
     * Result is cached for 30 seconds to avoid hammering the network on every request.
     */
    public function isOnline(): bool
    {
        if (! $this->isEdge()) {
            return false;
        }

        return Cache::remember('edge.is_online', 30, function (): bool {
            $state = EdgeDeviceState::current();
            $centralUrl = $state->central_url;

            if (! $centralUrl) {
                return false;
            }

            try {
                $response = Http::timeout(3)->get(rtrim($centralUrl, '/') . '/api/ping');

                return $response->successful();
            } catch (\Throwable) {
                return false;
            }
        });
    }

    public function isOffline(): bool
    {
        return $this->isEdge() && !$this->isOnline();
    }

    /** On central: always true. On edge offline: false. */
    public function canCreateClients(): bool
    {
        return !$this->isOffline();
    }

    /** Identity registration requires central DB access. */
    public function canRegisterIdentity(): bool
    {
        return !$this->isOffline();
    }

    /**
     * When edge is offline and program requires binding, return 'optional' so triage
     * is not completely blocked. Otherwise return the given program mode unchanged.
     */
    public function getEffectiveBindingMode(string $programMode): string
    {
        if ($this->isOffline() && $programMode === 'required') {
            return 'optional';
        }

        return $programMode;
    }

    /** On edge Pi, admin panel is always read-only; config changes must be made on central and re-synced. */
    public function isAdminReadOnly(): bool
    {
        return $this->isEdge();
    }

    /**
     * Whether sync-back (edge → central) and Edge settings form are enabled.
     * Design: edge never syncs back; only central configures what gets sent to edge.
     * When false, Site settings → Edge section inputs and Save are disabled.
     */
    public function syncBack(): bool
    {
        if ($this->isEdge()) {
            return false;
        }

        return (bool) config('app.sync_back', false);
    }

    /**
     * Whether bridge mode (edge proxying to central) is enabled.
     * Only relevant on edge; on central always false. When false, bridge-related
     * UI (e.g. "bridge active" banner) and behavior stay off. Set EDGE_BRIDGE_MODE=false in env.edge.
     */
    public function bridgeModeEnabled(): bool
    {
        if (! $this->isEdge()) {
            return false;
        }

        return (bool) config('app.edge_bridge_mode', false);
    }
}
