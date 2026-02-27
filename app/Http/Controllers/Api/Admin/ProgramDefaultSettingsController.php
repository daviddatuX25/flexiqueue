<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProgramDefaultSettingsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per ISSUES-ELABORATION §2: global default program settings (GET/PUT). Admin only.
 */
class ProgramDefaultSettingsController extends Controller
{
    private const ROW_ID = 1;

    /**
     * GET /api/admin/program-default-settings — Return current default settings (same shape as program.settings).
     */
    public function show(): JsonResponse
    {
        $row = $this->getRow();
        $settings = $row ? (json_decode($row->settings ?? '{}', true) ?? []) : [];

        return response()->json([
            'settings' => $this->normalizeSettings($settings),
        ]);
    }

    /**
     * PUT /api/admin/program-default-settings — Save default settings.
     */
    public function update(UpdateProgramDefaultSettingsRequest $request): JsonResponse
    {
        $settings = $request->validated()['settings'];
        $settings = $this->normalizeSettings($settings);

        $row = $this->getRow();
        if ($row) {
            DB::table('program_default_settings')
                ->where('id', self::ROW_ID)
                ->update(['settings' => json_encode($settings), 'updated_at' => now()]);
        } else {
            DB::table('program_default_settings')->insert([
                'id' => self::ROW_ID,
                'settings' => json_encode($settings),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['settings' => $settings]);
    }

    private function getRow(): ?object
    {
        if (! Schema::hasTable('program_default_settings')) {
            return null;
        }

        return DB::table('program_default_settings')->find(self::ROW_ID);
    }

    private function normalizeSettings(array $settings): array
    {
        return [
            'no_show_timer_seconds' => (int) ($settings['no_show_timer_seconds'] ?? 10),
            'require_permission_before_override' => (bool) ($settings['require_permission_before_override'] ?? true),
            'priority_first' => (bool) ($settings['priority_first'] ?? true),
            'balance_mode' => $settings['balance_mode'] ?? 'fifo',
            'station_selection_mode' => $settings['station_selection_mode'] ?? 'fixed',
            'alternate_ratio' => [
                (int) (($settings['alternate_ratio'] ?? [2, 1])[0] ?? 2),
                (int) (($settings['alternate_ratio'] ?? [2, 1])[1] ?? 1),
            ],
        ];
    }
}
