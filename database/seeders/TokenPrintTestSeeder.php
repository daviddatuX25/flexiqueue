<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Site;
use App\Models\Token;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Minimal data for TokenPrintTest: one site, one admin, two tokens with that site_id.
 * Used so controller resolveTokens finds tokens by site and ids.
 */
class TokenPrintTestSeeder extends Seeder
{
    public const ADMIN_EMAIL = 'token-print-test-admin@example.com';

    public function run(): void
    {
        $site = Site::firstOrCreate(
            ['slug' => 'default'],
            [
                'name' => 'Default Site',
                'api_key_hash' => Hash::make(Str::random(40)),
                'settings' => [],
                'edge_settings' => [],
            ]
        );

        $admin = User::firstOrCreate(
            ['email' => self::ADMIN_EMAIL],
            [
                'name' => 'Token Print Test Admin',
                'password' => Hash::make('password'),
                'is_active' => true,
                'site_id' => $site->id,
                'override_pin' => Hash::make('123456'),
                'override_qr_token' => Hash::make(Str::random(64)),
            ]
        );
        if (! $admin->site_id) {
            $admin->update(['site_id' => $site->id]);
        }
        User::assignGlobalRoleAndSyncProvisioning($admin, UserRole::Admin->value);

        foreach (['A1', 'A2'] as $physicalId) {
            $existing = Token::where('physical_id', $physicalId)->where('site_id', $site->id)->first();
            if ($existing) {
                if (empty($existing->qr_code_hash)) {
                    $existing->qr_code_hash = hash('sha256', Str::random(40).$physicalId);
                    $existing->save();
                }

                continue;
            }
            $token = new Token;
            $token->physical_id = $physicalId;
            $token->site_id = $site->id;
            $token->qr_code_hash = hash('sha256', Str::random(40).$physicalId);
            $token->status = 'available';
            $token->save();
        }
    }
}
