<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Per IDENTITY-BINDING-FINAL-IMPLEMENTATION-PLAN §2 Migration 1.
 * Replace identity_binding_mode 'optional' with 'required' in programs and program_default_settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->washoutPrograms();
            $this->washoutProgramDefaultSettings();
        });
    }

    public function down(): void
    {
        // No-op: reverting would require guessing which were originally 'optional'.
    }

    private function washoutPrograms(): void
    {
        $rows = DB::table('programs')
            ->select('id', 'settings')
            ->get();

        foreach ($rows as $row) {
            if ($row->settings === null) {
                continue;
            }
            $settings = json_decode($row->settings, true) ?: [];
            if (($settings['identity_binding_mode'] ?? '') !== 'optional') {
                continue;
            }
            $settings['identity_binding_mode'] = 'required';
            DB::table('programs')
                ->where('id', $row->id)
                ->update(['settings' => json_encode($settings)]);
        }
    }

    private function washoutProgramDefaultSettings(): void
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
            if (($settings['identity_binding_mode'] ?? '') !== 'optional') {
                continue;
            }
            $settings['identity_binding_mode'] = 'required';
            DB::table('program_default_settings')
                ->where('id', $row->id)
                ->update(['settings' => json_encode($settings)]);
        }
    }
};
