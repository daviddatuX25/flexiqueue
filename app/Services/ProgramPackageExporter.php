<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Process;
use App\Models\Program;
use App\Models\ServiceTrack;
use App\Models\Site;
use App\Models\Station;
use App\Models\Token;
use App\Models\TrackStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Exports a complete program package for edge sync (central → Pi).
 * Per docs/final-edge-mode-rush-plann.md [DF-05].
 */
class ProgramPackageExporter
{
    /**
     * Export program + site data as a package array (manifest, sections, tts_files).
     * Edge settings on the site control which optional sections (tokens, clients, tts) are included.
     */
    public function export(Program $program, Site $site): array
    {
        $edgeSettings = $site->edge_settings ?? [];
        $syncTokens = (bool) ($edgeSettings['sync_tokens'] ?? false);
        $syncClients = (bool) ($edgeSettings['sync_clients'] ?? false);
        $syncTts = (bool) ($edgeSettings['sync_tts'] ?? false);

        $sections = [];

        $sections['site'] = $site->toArray();
        $sections['program'] = $program->toArray();

        $tracks = ServiceTrack::where('program_id', $program->id)
            ->get(['id', 'program_id', 'name', 'description', 'is_default', 'color_code', 'created_at', 'updated_at'])
            ->toArray();
        $sections['tracks'] = $tracks;
        $trackIds = array_column($tracks, 'id');

        $sections['processes'] = Process::where('program_id', $program->id)
            ->get(['id', 'program_id', 'name', 'description', 'expected_time_seconds', 'created_at', 'updated_at'])
            ->toArray();

        $stations = Station::where('program_id', $program->id)->get()->toArray();
        $sections['stations'] = $stations;
        $stationIds = array_column($stations, 'id');

        $sections['steps'] = TrackStep::whereIn('track_id', $trackIds)
            ->get(['id', 'track_id', 'station_id', 'process_id', 'step_order', 'is_required', 'estimated_minutes', 'created_at', 'updated_at'])
            ->toArray();

        $stationProcessRows = DB::table('station_process')
            ->whereIn('station_id', $stationIds)
            ->get(['station_id', 'process_id']);
        $sections['station_process'] = $stationProcessRows->map(fn ($r) => (array) $r)->values()->all();

        // Per plan: use raw DB so password/override_pin/override_qr_token are included (User $hidden would strip them).
        $userRows = DB::table('users')
            ->where('site_id', $site->id)
            ->get([
                'id', 'site_id', 'name', 'email', 'password', 'role',
                'override_pin', 'override_qr_token',
                'assigned_station_id', 'is_active', 'availability_status',
                'staff_triage_allow_hid_barcode', 'staff_triage_allow_camera_scanner',
                'created_at', 'updated_at',
            ]);
        $sections['users'] = $userRows->map(fn ($r) => (array) $r)->values()->all();

        if ($syncTokens) {
            $sections['tokens'] = Token::forSite($site->id)
                ->whereHas('programs', fn ($q) => $q->where('programs.id', $program->id))
                ->get([
                    'id', 'site_id', 'physical_id', 'pronounce_as', 'qr_code_hash',
                    'status', 'tts_audio_path', 'tts_status', 'tts_settings',
                    'is_global', 'created_at', 'updated_at',
                ])
                ->toArray();
            $sections['program_token'] = DB::table('program_token')
                ->where('program_id', $program->id)
                ->get(['program_id', 'token_id', 'created_at'])
                ->map(fn ($r) => (array) $r)
                ->values()
                ->all();
        } else {
            $sections['tokens'] = [];
            $sections['program_token'] = [];
        }

        if ($syncClients) {
            // Never include mobile_encrypted or mobile_hash per plan.
            $sections['clients'] = Client::forSite($site->id)
                ->get([
                    'id', 'site_id',
                    'first_name', 'middle_name', 'last_name', 'birth_date',
                    'address_line_1', 'address_line_2', 'city', 'state',
                    'postal_code', 'country',
                    'identity_hash',
                    'created_at', 'updated_at',
                ])
                ->toArray();
        } else {
            $sections['clients'] = [];
        }

        if ($syncTts) {
            $sections['tts_files'] = $this->collectTtsFilePaths($sections);
        } else {
            $sections['tts_files'] = [];
        }

        $manifest = [
            'program_id' => $program->id,
            'site_id' => $site->id,
            'exported_at' => now()->toIso8601String(),
            'sync_tokens' => $syncTokens,
            'sync_clients' => $syncClients,
            'sync_tts' => $syncTts,
            'checksums' => [
                'site' => hash('sha256', json_encode($sections['site'])),
                'program' => hash('sha256', json_encode($sections['program'])),
                'tracks' => hash('sha256', json_encode($sections['tracks'])),
                'steps' => hash('sha256', json_encode($sections['steps'])),
                'processes' => hash('sha256', json_encode($sections['processes'])),
                'stations' => hash('sha256', json_encode($sections['stations'])),
                'station_process' => hash('sha256', json_encode($sections['station_process'])),
                'users' => hash('sha256', json_encode($sections['users'])),
                'tokens' => hash('sha256', json_encode($sections['tokens'])),
                'clients' => hash('sha256', json_encode($sections['clients'])),
            ],
        ];

        return [
            'manifest' => $manifest,
            'site' => $sections['site'],
            'program' => $sections['program'],
            'tracks' => $sections['tracks'],
            'steps' => $sections['steps'],
            'processes' => $sections['processes'],
            'stations' => $sections['stations'],
            'station_process' => $sections['station_process'],
            'users' => $sections['users'],
            'tokens' => $sections['tokens'],
            'program_token' => $sections['program_token'],
            'clients' => $sections['clients'],
            'tts_files' => $sections['tts_files'],
        ];
    }

    /**
     * Collect TTS audio paths from tokens and stations; dedupe and verify existence on local disk.
     */
    private function collectTtsFilePaths(array $sections): array
    {
        $paths = [];
        $disk = Storage::disk('local');

        foreach ($sections['tokens'] ?? [] as $token) {
            if (! empty($token['tts_audio_path']) && is_string($token['tts_audio_path'])) {
                $paths[] = $token['tts_audio_path'];
            }
            $languages = $token['tts_settings']['languages'] ?? [];
            if (is_array($languages)) {
                foreach ($languages as $lang) {
                    if (! empty($lang['audio_path']) && is_string($lang['audio_path'])) {
                        $paths[] = $lang['audio_path'];
                    }
                }
            }
        }

        foreach ($sections['stations'] ?? [] as $station) {
            $tts = $station['settings']['tts']['languages'] ?? [];
            if (is_array($tts)) {
                foreach ($tts as $lang) {
                    if (! empty($lang['audio_path']) && is_string($lang['audio_path'])) {
                        $paths[] = $lang['audio_path'];
                    }
                }
            }
        }

        $paths = array_values(array_unique(array_filter($paths)));
        $existing = [];
        foreach ($paths as $path) {
            if ($disk->exists($path)) {
                $existing[] = $path;
            }
        }

        return $existing;
    }
}
