<?php

namespace Database\Seeders\Central;

use App\Models\Site;
use App\Validation\EdgeSettingsValidator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates two org sites for central server. Per docs/seeder-plan.txt §3.
 */
class CentralSiteSeeder extends Seeder
{
    public function run(): void
    {
        $validator = app(EdgeSettingsValidator::class);

        Site::firstOrCreate(
            ['slug' => 'tagudin-mswdo'],
            [
                'name' => 'Tagudin MSWDO',
                'is_default' => false,
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

        Site::firstOrCreate(
            ['slug' => 'candon-mswdo'],
            [
                'name' => 'Candon City MSWDO',
                'is_default' => false,
                'api_key_hash' => Hash::make(Str::random(40)),
                'settings' => [],
                'edge_settings' => $validator->validate([
                    'sync_tokens' => true,
                    'sync_clients' => false,
                    'sync_tts' => true,
                    'bridge_enabled' => true,
                ]),
            ]
        );
    }
}
