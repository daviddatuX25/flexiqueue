<?php

use App\Models\Site;
use App\Models\TtsPlatformBudget;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        TtsPlatformBudget::query()->update(['mode' => 'chars']);

        Site::query()->chunkById(200, function ($sites): void {
            foreach ($sites as $site) {
                $settings = is_array($site->settings) ? $site->settings : [];
                $budget = $settings['tts_budget'] ?? null;
                if (! is_array($budget)) {
                    continue;
                }

                if (($budget['mode'] ?? 'chars') === 'chars') {
                    continue;
                }

                $budget['mode'] = 'chars';
                $settings['tts_budget'] = $budget;
                $site->settings = $settings;
                $site->save();
            }
        });
    }

    public function down(): void
    {
        // Irreversible normalization: mode stays chars-only by policy.
    }
};
