<?php

namespace App\Console\Commands;

use App\Models\EdgeDeviceState;
use App\Services\EdgeModeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class EdgeHeartbeat extends Command
{
    protected $signature = 'edge:heartbeat';

    protected $description = 'Send a heartbeat to the central server and apply any config changes pushed back.';

    public function handle(EdgeModeService $edgeModeService): int
    {
        if (! $edgeModeService->isEdge()) {
            $this->error('This command only runs on edge devices (APP_MODE=edge).');

            return self::FAILURE;
        }

        $state = EdgeDeviceState::current();

        if (! $state->paired_at || ! $state->device_token) {
            $this->warn('Device not yet paired. Skipping heartbeat.');

            return self::SUCCESS;
        }

        $centralUrl = rtrim($state->central_url, '/');
        $appVersion = @file_get_contents(storage_path('app/version.txt')) ?: null;

        try {
            $response = Http::timeout(10)
                ->withToken($state->device_token)
                ->post("{$centralUrl}/api/edge/heartbeat", [
                    'session_active'  => (bool) $state->session_active,
                    'sync_mode'       => $state->sync_mode,
                    'last_synced_at'  => $state->last_synced_at?->toIso8601String(),
                    'package_version' => $state->package_version,
                    'app_version'     => $appVersion,
                ]);

            if (! $response->successful()) {
                $this->warn("Heartbeat failed with HTTP {$response->status()}.");

                return self::FAILURE;
            }

            $data = $response->json();

            if ($data['revoked'] ?? false) {
                $state->update([
                    'paired_at'    => null,
                    'device_token' => null,
                ]);
                $this->warn('Device has been revoked by central. Device requires re-pairing.');

                return self::SUCCESS;
            }

            $updates = [];

            if (isset($data['sync_mode'])) {
                $updates['sync_mode'] = $data['sync_mode'];
            }

            if (isset($data['supervisor_admin_access'])) {
                $updates['supervisor_admin_access'] = (bool) $data['supervisor_admin_access'];
            }

            if (! empty($updates)) {
                $state->update($updates);
            }

        } catch (\Throwable $e) {
            $this->warn("Heartbeat failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Heartbeat sent successfully.');

        return self::SUCCESS;
    }
}
