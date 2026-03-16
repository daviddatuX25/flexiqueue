<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Core import logic for edge package (Steps 3–7 from [DF-08]).
 * Used by EdgeImportPackage command and ImportProgramPackageJob.
 * Per docs/final-edge-mode-rush-plann.md.
 */
class EdgePackageImportService
{
    /**
     * Fetch package from central, validate checksums, run DB import, download TTS, write status file.
     *
     * @throws RuntimeException on fetch failure, checksum mismatch, or DB error
     */
    public function runImport(int $programId, string $centralUrl, string $apiKey): void
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Accept' => 'application/json',
        ])->timeout(60)->get("{$centralUrl}/api/admin/programs/{$programId}/package");

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch package. HTTP ' . $response->status());
        }

        $package = $response->json();
        $manifest = $package['manifest'] ?? null;
        $checksums = $manifest['checksums'] ?? [];

        if (empty($manifest)) {
            throw new RuntimeException('Invalid package: missing manifest.');
        }

        foreach ($checksums as $section => $expectedHash) {
            $actual = hash('sha256', json_encode($package[$section] ?? []));
            if ($actual !== $expectedHash) {
                throw new RuntimeException("Checksum mismatch for section: {$section}.");
            }
        }

        DB::transaction(function () use ($package, $manifest): void {
            DB::table('programs')->upsert(
                [$package['program']],
                ['id'],
                array_keys($package['program'])
            );

            if (! empty($package['tracks'])) {
                DB::table('service_tracks')->upsert(
                    $package['tracks'],
                    ['id'],
                    ['name', 'description', 'is_default', 'color_code', 'updated_at']
                );
            }

            if (! empty($package['processes'])) {
                DB::table('processes')->upsert(
                    $package['processes'],
                    ['id'],
                    ['name', 'description', 'expected_time_seconds', 'updated_at']
                );
            }

            if (! empty($package['stations'])) {
                DB::table('stations')->upsert(
                    $package['stations'],
                    ['id'],
                    ['name', 'capacity', 'client_capacity', 'holding_capacity', 'settings', 'is_active', 'updated_at']
                );
            }

            if (! empty($package['steps'])) {
                DB::table('track_steps')->upsert(
                    $package['steps'],
                    ['id'],
                    ['station_id', 'process_id', 'step_order', 'is_required', 'estimated_minutes', 'updated_at']
                );
            }

            if (! empty($package['station_process'])) {
                DB::table('station_process')->upsert(
                    $package['station_process'],
                    ['station_id', 'process_id'],
                    []
                );
            }

            DB::table('users')->upsert(
                $package['users'],
                ['id'],
                ['name', 'email', 'password', 'role', 'override_pin', 'override_qr_token', 'assigned_station_id', 'is_active', 'availability_status', 'staff_triage_allow_hid_barcode', 'staff_triage_allow_camera_scanner', 'updated_at']
            );

            if (! empty($manifest['sync_tokens']) && ! empty($package['tokens'])) {
                DB::table('tokens')->upsert(
                    $package['tokens'],
                    ['id'],
                    ['site_id', 'physical_id', 'pronounce_as', 'qr_code_hash', 'status', 'tts_audio_path', 'tts_status', 'tts_settings', 'is_global', 'created_at', 'updated_at']
                );
            }

            if (! empty($manifest['sync_tokens']) && ! empty($package['program_token'])) {
                DB::table('program_token')->upsert(
                    $package['program_token'],
                    ['program_id', 'token_id'],
                    ['created_at']
                );
            }

            if (! empty($manifest['sync_clients']) && ! empty($package['clients'])) {
                DB::table('clients')->upsert(
                    $package['clients'],
                    ['id'],
                    ['site_id', 'first_name', 'middle_name', 'last_name', 'birth_date', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country', 'identity_hash', 'created_at', 'updated_at']
                );
            }
        });

        if (! empty($manifest['sync_tts']) && ! empty($package['tts_files'])) {
            foreach ($package['tts_files'] as $filePath) {
                $encoded = implode('/', array_map('rawurlencode', explode('/', $filePath)));
                $fileResponse = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                ])->timeout(30)->get("{$centralUrl}/api/admin/programs/{$programId}/tts-files/{$encoded}");

                if ($fileResponse->ok()) {
                    Storage::disk('local')->put($filePath, $fileResponse->body());
                }
            }
        }

        Storage::disk('local')->put('edge_package_imported.json', json_encode([
            'program_id' => $manifest['program_id'],
            'site_id' => $manifest['site_id'],
            'imported_at' => now()->toIso8601String(),
            'manifest_hash' => hash('sha256', json_encode($manifest)),
            'sync_tokens' => $manifest['sync_tokens'],
            'sync_clients' => $manifest['sync_clients'],
            'sync_tts' => $manifest['sync_tts'],
            'status' => 'complete',
        ]));
    }
}
