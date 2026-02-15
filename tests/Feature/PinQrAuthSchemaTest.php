<?php

namespace Tests\Feature;

use App\Models\TemporaryAuthorization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Per docs/plans/PIN-QR-AUTHORIZATION-SYSTEM.md AUTH-1:
 * Schema: temporary_authorizations table, users.override_qr_token column.
 */
class PinQrAuthSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_temporary_authorizations_table_has_required_columns(): void
    {
        $user = User::factory()->create();

        $auth = TemporaryAuthorization::create([
            'user_id' => $user->id,
            'token_hash' => Hash::make('secret123'),
            'type' => 'pin',
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->assertDatabaseHas('temporary_authorizations', [
            'id' => $auth->id,
            'user_id' => $user->id,
            'type' => 'pin',
        ]);
        $this->assertNull($auth->used_at);
    }

    public function test_users_override_qr_token_column_stores_hashed_value(): void
    {
        $user = User::factory()->create();
        $user->override_qr_token = Hash::make('qr-token-abc');
        $user->save();

        $this->assertNotNull($user->override_qr_token);
        $this->assertTrue(Hash::check('qr-token-abc', $user->fresh()->override_qr_token));
    }

    public function test_temporary_authorization_type_qr_stores_correctly(): void
    {
        $user = User::factory()->create();

        $auth = TemporaryAuthorization::create([
            'user_id' => $user->id,
            'token_hash' => Hash::make('qr-token-xyz'),
            'type' => 'qr',
            'expires_at' => now()->addMinutes(5),
            'used_at' => now(),
        ]);

        $this->assertDatabaseHas('temporary_authorizations', [
            'id' => $auth->id,
            'type' => 'qr',
        ]);
        $this->assertNotNull($auth->used_at);
    }
}
