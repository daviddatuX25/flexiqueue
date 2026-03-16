<?php

namespace Database\Seeders\Edge;

use App\Models\Site;
use App\Validation\EdgeSettingsValidator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates the single edge site. Per docs/seeder-plan.txt §9.
 */
class EdgeSiteSeeder extends Seeder
{
    public function run(): void
    {
        $validator = app(EdgeSettingsValidator::class);

        Site::firstOrCreate(
            ['slug' => 'tagudin-mswdo-field'],
            [
                'name' => 'Tagudin MSWDO Field Office',
                'is_default' => true,
                'api_key_hash' => Hash::make(Str::random(40)),
                'settings' => [],
                'edge_settings' => $validator->validate([
                    'sync_tokens' => true,
                    'sync_clients' => true,
                    'sync_tts' => true,
                    'bridge_enabled' => false,
                ]),
            ]
        );
    }
}
