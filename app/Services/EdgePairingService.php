<?php

namespace App\Services;

use App\Models\EdgeDevice;
use App\Models\EdgePairingCode;
use App\Models\Site;
use Illuminate\Support\Str;

class EdgePairingService
{
    /**
     * Returns true when the site's max_edge_devices limit allows pairing a new device.
     */
    public function canGenerateCode(int $siteId): bool
    {
        $site = Site::findOrFail($siteId);
        $maxDevices = $site->settings['max_edge_devices'] ?? 0;

        if ($maxDevices <= 0) {
            return false;
        }

        $activeCount = EdgeDevice::where('site_id', $siteId)
            ->whereNull('revoked_at')
            ->count();

        return $activeCount < $maxDevices;
    }

    /**
     * Generate a new 8-character uppercase alphanumeric pairing code with a 10-minute TTL.
     * The plain code is returned; the hash is stored in the DB.
     */
    public function generateCode(int $siteId, string $deviceName): string
    {
        // Purge expired unconsumed codes to keep the table clean
        EdgePairingCode::where('expires_at', '<', now())
            ->whereNull('consumed_at')
            ->delete();

        $plain = strtoupper(Str::random(8));

        EdgePairingCode::create([
            'site_id'     => $siteId,
            'code_hash'   => hash('sha256', $plain),
            'device_name' => $deviceName,
            'expires_at'  => now()->addMinutes(10),
            'created_at'  => now(),
        ]);

        return $plain;
    }

    /**
     * Validate a pairing code, create the EdgeDevice record, mark the code consumed.
     * Returns array with: device_token, device_id, site_id, site_name, id_offset.
     * Throws \InvalidArgumentException if the code is invalid/expired/consumed.
     */
    public function validateAndConsume(string $plainCode): array
    {
        $codeHash = hash('sha256', $plainCode);
        $code     = EdgePairingCode::where('code_hash', $codeHash)->first();

        if (! $code || ! $code->isUsable()) {
            throw new \InvalidArgumentException('Invalid or expired pairing code.');
        }

        // Assign ID offset: (device_count + 1) × 10,000,000 per spec §10.2
        $deviceCount = EdgeDevice::where('site_id', $code->site_id)->count();
        $idOffset    = ($deviceCount + 1) * 10_000_000;

        // Generate a cryptographically secure 64-char device token
        $plainToken = Str::random(64);
        $tokenHash  = hash('sha256', $plainToken);

        $device = EdgeDevice::create([
            'site_id'                 => $code->site_id,
            'name'                    => $code->device_name,
            'device_token_hash'       => $tokenHash,
            'id_offset'               => $idOffset,
            'sync_mode'               => 'auto',
            'supervisor_admin_access' => false,
            'session_active'          => false,
            'update_status'           => 'up_to_date',
            'paired_at'               => now(),
        ]);

        $code->update(['consumed_at' => now()]);

        return [
            'device_token' => $plainToken,
            'device_id'    => $device->id,
            'site_id'      => $device->site_id,
            'site_name'    => $device->site->name,
            'id_offset'    => $idOffset,
        ];
    }
}
