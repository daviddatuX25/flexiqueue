<?php

namespace App\Services;

use App\Models\EdgeDeviceState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

class EdgeDeviceSetupService
{
    public function __construct(private readonly bool $writeEnv = true) {}

    /**
     * Call central /api/edge/pair, persist results to EdgeDeviceState, optionally update .env.
     *
     * @throws \RuntimeException if pairing fails.
     */
    public function setup(string $centralUrl, string $pairingCode, string $syncMode): void
    {
        $centralUrl = rtrim($centralUrl, '/');

        $response = Http::timeout(10)->post("{$centralUrl}/api/edge/pair", [
            'pairing_code' => $pairingCode,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                $response->json('error') ?? 'Pairing failed. Check the code and try again.'
            );
        }

        $data = $response->json();

        EdgeDeviceState::updateOrCreate(
            ['id' => 1],
            [
                'central_url'             => $centralUrl,
                'device_token'            => $data['device_token'],
                'site_id'                 => $data['site_id'],
                'site_name'               => $data['site_name'],
                'id_offset'               => $data['id_offset'],
                'sync_mode'               => $syncMode,
                'paired_at'               => now(),
                'session_active'          => false,
                'supervisor_admin_access' => false,
                'is_revoked'              => false,
            ]
        );

        if ($this->writeEnv) {
            $this->writeCentralUrlToEnv($centralUrl);
            Artisan::call('config:clear');
            Artisan::call('config:cache');
        }
    }

    private function writeCentralUrlToEnv(string $centralUrl): void
    {
        $envPath    = base_path('.env');
        $envContent = file_get_contents($envPath);

        if (str_contains($envContent, 'CENTRAL_URL=')) {
            $envContent = preg_replace('/^CENTRAL_URL=.*/m', "CENTRAL_URL={$centralUrl}", $envContent);
        } else {
            $envContent .= "\nCENTRAL_URL={$centralUrl}";
        }

        file_put_contents($envPath, $envContent);
    }
}
