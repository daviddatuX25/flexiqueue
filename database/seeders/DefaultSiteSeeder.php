<?php

namespace Database\Seeders;

use App\Models\Program;
use App\Models\Site;
use App\Models\User;
use App\Validation\EdgeSettingsValidator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DefaultSiteSeeder extends Seeder
{
    public function run(): void
    {
        $edgeSettingsValidator = app(EdgeSettingsValidator::class);

        $site = Site::firstOrCreate(
            ['slug' => 'default'],
            [
                'name' => 'Default Site',
                'api_key_hash' => Hash::make(Str::random(40)),
                'settings' => [],
                'edge_settings' => $edgeSettingsValidator->validate([
                    'bridge_enabled' => false,
                    'sync_clients' => false,
                ]),
            ]
        );

        Program::whereNull('site_id')->update(['site_id' => $site->id]);
        User::whereNull('site_id')->update(['site_id' => $site->id]);
    }
}

