<?php

namespace App\Console\Commands;

use App\Jobs\ImportProgramPackageJob;
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
                    'is_revoked'   => true,   // E9.4: persist for EdgeBootGuard redirect
                ]);
                $this->warn('Device has been revoked by central. Redirecting to revoked page.');

                return self::SUCCESS;
            }

            // Dump session signal — end session locally and notify central
            if ($data['dump_session'] ?? false) {
                $state->update([
                    'session_active'    => false,
                    'active_program_id' => null,
                ]);
                $this->info('Dump signal received. Session ended locally. Notifying central...');

                Http::timeout(10)
                    ->withToken($state->device_token)
                    ->post("{$centralUrl}/api/edge/session/end");
            }

            // Session voided by central force-cancel
            if ($data['session_voided'] ?? false) {
                $voidedAt = $data['voided_at'] ?? now()->toIso8601String();

                // Archive path for potential manual recovery
                $archiveDir = storage_path('app/voided-sessions/' . date('Y-m-d'));
                if (! is_dir($archiveDir)) {
                    @mkdir($archiveDir, 0755, true);
                }
                $archivePath = $archiveDir . '/voided-' . time() . '.json';
                @file_put_contents($archivePath, json_encode([
                    'voided_at'          => $voidedAt,
                    'active_program_id'  => $state->active_program_id,
                    'session_active'     => $state->session_active,
                    'note'               => 'Session force-cancelled by central admin. Data archived for manual recovery.',
                ]));

                $state->update([
                    'session_active'    => false,
                    'active_program_id' => null,
                ]);

                $this->warn("Session was voided by central at {$voidedAt}. Local state cleared. Archive: {$archivePath}");
            }

            $updates = [];

            if (isset($data['sync_mode'])) {
                $updates['sync_mode'] = $data['sync_mode'];
            }

            if (isset($data['supervisor_admin_access'])) {
                $updates['supervisor_admin_access'] = (bool) $data['supervisor_admin_access'];
            }

            if (isset($data['package_stale'])) {
                $updates['package_stale'] = (bool) $data['package_stale'];
            }

            if (isset($data['update_available'])) {
                $updates['update_available'] = (bool) $data['update_available'];
            }

            if (! empty($updates)) {
                $state->update($updates);
            }

            // Auto-dispatch re-import when package is stale and device is in waiting state
            $packageStale = (bool) ($data['package_stale'] ?? false);
            if ($packageStale && ! $state->session_active && $state->active_program_id !== null) {
                $apiKey = config('app.central_api_key');
                ImportProgramPackageJob::dispatch($state->active_program_id, $centralUrl, $apiKey);
            }

        } catch (\Throwable $e) {
            $this->warn("Heartbeat failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Heartbeat sent successfully.');

        return self::SUCCESS;
    }
}
