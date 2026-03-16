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
     * Encode array/object values for the given keys as JSON strings for raw DB upsert.
     * Package data from central uses decoded JSON (e.g. settings); SQLite expects strings.
     */
    private static function encodeJsonColumns(array $row, array $jsonKeys): array
    {
        foreach ($jsonKeys as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }
            $value = $row[$key];
            if (is_array($value) || is_object($value)) {
                $row[$key] = json_encode($value);
            }
        }
        return $row;
    }

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
            // Site and users first so program.site_id and program.created_by FKs exist.
            if (! empty($package['site'])) {
                $siteRow = self::encodeJsonColumns($package['site'], ['settings', 'edge_settings']);
                DB::table('sites')->upsert(
                    [$siteRow],
                    ['id'],
                    array_keys($siteRow)
                );
            }

            DB::table('users')->upsert(
                $package['users'],
                ['id'],
                ['site_id', 'name', 'email', 'password', 'role', 'override_pin', 'override_qr_token', 'assigned_station_id', 'is_active', 'availability_status', 'staff_triage_allow_hid_barcode', 'staff_triage_allow_camera_scanner', 'updated_at']
            );

            $programRow = self::encodeJsonColumns($package['program'], ['settings']);
            DB::table('programs')->upsert(
                [$programRow],
                ['id'],
                array_keys($programRow)
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
                $stationRows = array_map(
                    fn (array $row) => self::encodeJsonColumns($row, ['settings']),
                    $package['stations']
                );
                DB::table('stations')->upsert(
                    $stationRows,
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

            if (! empty($manifest['sync_tokens']) && ! empty($package['tokens'])) {
                $tokenRows = array_map(
                    fn (array $row) => self::encodeJsonColumns($row, ['tts_settings']),
                    $package['tokens']
                );
                DB::table('tokens')->upsert(
                    $tokenRows,
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
