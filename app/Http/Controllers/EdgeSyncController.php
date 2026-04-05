<?php

namespace App\Http\Controllers;

use App\Models\EdgeDeviceState;
use App\Models\EdgeSyncReceipt;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class EdgeSyncController extends Controller
{
    public function show(): Response
    {
        $state = EdgeDeviceState::current();

        return Inertia::render('Edge/Sync', [
            'device'   => $this->buildDevicePayload($state),
            'import'   => $this->buildImportStatus(),
            'receipts' => EdgeSyncReceipt::orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(fn (EdgeSyncReceipt $r) => [
                    'id'              => $r->id,
                    'batch_id'        => $r->batch_id,
                    'status'          => $r->status,
                    'payload_summary' => $r->payload_summary,
                    'started_at'      => $r->started_at?->toIso8601String(),
                    'completed_at'    => $r->completed_at?->toIso8601String(),
                ])
                ->toArray(),
        ]);
    }

    private function buildDevicePayload(EdgeDeviceState $state): array
    {
        return [
            'paired_at'           => $state->paired_at?->toIso8601String(),
            'central_url'         => $state->central_url,
            'site_name'           => $state->site_name,
            'active_program_id'   => $state->active_program_id,
            'active_program_name' => $state->active_program_name,
            'package_version'     => $state->package_version,
            'package_stale'       => (bool) $state->package_stale,
            'update_available'    => (bool) $state->update_available,
            'session_active'      => (bool) $state->session_active,
            'last_synced_at'      => $state->last_synced_at?->toIso8601String(),
            'app_version'         => $state->app_version,
        ];
    }

    private function buildImportStatus(): array
    {
        $lockPath   = storage_path('app/edge_import_running.lock');
        $lockExists = file_exists($lockPath)
            || Storage::disk('local')->exists('edge_import_running.lock');

        if ($lockExists) {
            $data = ['status' => 'running'];
            if (Storage::disk('local')->exists('edge_package_imported.json')) {
                $raw = json_decode(
                    Storage::disk('local')->get('edge_package_imported.json'),
                    true
                );
                if (is_array($raw)) {
                    $data = array_merge($raw, $data); // 'running' always wins
                }
            }

            return $data;
        }

        if (! Storage::disk('local')->exists('edge_package_imported.json')) {
            return ['status' => 'never_synced'];
        }

        $contents = Storage::disk('local')->get('edge_package_imported.json');
        $data     = json_decode($contents, true);

        return is_array($data) ? $data : ['status' => 'unknown'];
    }
}
