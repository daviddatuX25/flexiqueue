<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Backfill identity_binding_mode => "disabled" into existing JSON settings
     * for programs and program_default_settings where the key is absent.
     * Wrapped in a transaction so we never leave rows in a mixed state.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->backfillPrograms();
            $this->backfillProgramDefaultSettings();
        });
    }

    public function down(): void
    {
        // No-op: removing identity_binding_mode from historical JSON is not required,
        // and ProgramSettings already defaults to disabled when the key is absent/invalid.
    }

    private function backfillPrograms(): void
    {
        $rows = DB::table('programs')
            ->select('id', 'settings')
            ->get();

        foreach ($rows as $row) {
            if ($row->settings === null) {
                continue;
            }

            $settings = json_decode($row->settings, true) ?: [];

            if (array_key_exists('identity_binding_mode', $settings)) {
                continue;
            }

            $settings['identity_binding_mode'] = 'disabled';

            DB::table('programs')
                ->where('id', $row->id)
                ->update([
                    'settings' => json_encode($settings),
                ]);
        }
    }

    private function backfillProgramDefaultSettings(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('program_default_settings')) {
            return;
        }

        $rows = DB::table('program_default_settings')
            ->select('id', 'settings')
            ->get();

        foreach ($rows as $row) {
            if ($row->settings === null) {
                continue;
            }

            $settings = json_decode($row->settings, true) ?: [];

            if (array_key_exists('identity_binding_mode', $settings)) {
                continue;
            }

            $settings['identity_binding_mode'] = 'disabled';

            DB::table('program_default_settings')
                ->where('id', $row->id)
                ->update([
                    'settings' => json_encode($settings),
                ]);
        }
    }
};

