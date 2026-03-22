<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\UserRole;
use App\Models\TtsAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * ElevenLabs integration API is super_admin only (per routes/web.php).
 */
class ElevenLabsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_elevenlabs_integration_status(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'site_id' => null]);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/integrations/elevenlabs');

        $response->assertOk();
        $response->assertJsonStructure([
            'status',
            'driver',
            'model_id',
            'default_voice_id',
            'voices_count',
            'accounts',
            'active_account_id',
        ]);
        $data = $response->json();
        $this->assertContains($data['status'], ['connected', 'not_configured']);
        $this->assertIsString($data['driver']);
        $this->assertIsString($data['model_id']);
        $this->assertIsString($data['default_voice_id']);
        $this->assertIsInt($data['voices_count']);
        $this->assertIsArray($data['accounts']);
    }

    public function test_staff_cannot_view_elevenlabs_integration_status(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $response = $this->actingAs($staff)
            ->getJson('/api/admin/integrations/elevenlabs');

        $response->assertForbidden();
    }

    public function test_admin_can_create_elevenlabs_account(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/user' => Http::response(['subscription' => []], 200),
        ]);

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'site_id' => null]);
        $this->actingAs($admin);
        Session::start();

        $response = $this->withHeader('X-CSRF-TOKEN', Session::token())
            ->postJson('/api/admin/integrations/elevenlabs/accounts', [
                'label' => 'Test account',
                'api_key' => 'sk_test_key_12345678901234567890',
                'model_id' => 'eleven_multilingual_v2',
            ]);

        $response->assertCreated();
        $response->assertJsonStructure(['id', 'label', 'provider', 'model_id', 'is_active', 'masked_api_key']);
        $this->assertDatabaseHas('tts_accounts', ['label' => 'Test account', 'provider' => 'elevenlabs']);
    }

    public function test_admin_can_activate_elevenlabs_account(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'site_id' => null]);
        $account = TtsAccount::create([
            'label' => 'Inactive',
            'api_key' => Crypt::encryptString('sk_test'),
            'model_id' => 'eleven_multilingual_v2',
            'is_active' => false,
        ]);

        $this->actingAs($admin);
        Session::start();

        $response = $this->withHeader('X-CSRF-TOKEN', Session::token())
            ->postJson("/api/admin/integrations/elevenlabs/accounts/{$account->id}/activate");

        $response->assertOk();
        $this->assertTrue($account->fresh()->is_active);
    }

    public function test_admin_can_delete_elevenlabs_account(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'site_id' => null]);
        $account = TtsAccount::create([
            'label' => 'To delete',
            'api_key' => Crypt::encryptString('sk_test'),
            'model_id' => 'eleven_multilingual_v2',
            'is_active' => false,
        ]);

        $this->actingAs($admin);
        Session::start();

        $response = $this->withHeader('X-CSRF-TOKEN', Session::token())
            ->deleteJson("/api/admin/integrations/elevenlabs/accounts/{$account->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('tts_accounts', ['id' => $account->id]);
    }

    public function test_admin_can_fetch_voices(): void
    {
        config(['tts.driver' => 'elevenlabs']);

        Http::fake([
            'api.elevenlabs.io/v1/voices' => Http::response([
                'voices' => [
                    ['voice_id' => 'v1', 'name' => 'Rachel', 'labels' => []],
                ],
            ], 200),
        ]);

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'site_id' => null]);
        TtsAccount::create([
            'label' => 'Active',
            'api_key' => Crypt::encryptString('sk_test_key'),
            'model_id' => 'eleven_multilingual_v2',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/integrations/elevenlabs/voices');

        $response->assertOk();
        $response->assertJsonStructure(['voices']);
        $voices = $response->json('voices');
        $this->assertNotEmpty($voices);
        $this->assertSame('Rachel', $voices[0]['name']);
        $this->assertSame('v1', $voices[0]['id']);
    }

    public function test_staff_cannot_create_elevenlabs_account(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $this->actingAs($staff);
        Session::start();

        $response = $this->withHeader('X-CSRF-TOKEN', Session::token())
            ->postJson('/api/admin/integrations/elevenlabs/accounts', [
                'label' => 'Test',
                'api_key' => 'sk_test_12345',
                'model_id' => 'eleven_multilingual_v2',
            ]);

        $response->assertForbidden();
    }

    public function test_staff_cannot_fetch_voices(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $response = $this->actingAs($staff)
            ->getJson('/api/admin/integrations/elevenlabs/voices');

        $response->assertForbidden();
    }

    public function test_admin_can_fetch_usage_when_active_account_exists(): void
    {
        config(['tts.driver' => 'elevenlabs']);

        Http::fake([
            'api.elevenlabs.io/v1/user/subscription' => Http::response([
                'character_count' => 12500,
                'character_limit' => 30000,
                'next_character_count_reset_unix' => strtotime('+1 month'),
                'tier' => 'starter',
            ], 200),
            'api.elevenlabs.io/v1/usage/character-stats*' => Http::response([
                'time' => [time() * 1000],
                'usage' => ['All' => [1000]],
            ], 200),
        ]);

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'site_id' => null]);
        TtsAccount::create([
            'label' => 'Active',
            'api_key' => Crypt::encryptString('sk_test_key'),
            'model_id' => 'eleven_multilingual_v2',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/integrations/elevenlabs/usage');

        $response->assertOk();
        $response->assertJsonStructure(['subscription', 'usage_time_series']);
        $this->assertNotNull($response->json('subscription'));
        $this->assertSame(12500, $response->json('subscription.character_count'));
        $this->assertSame(30000, $response->json('subscription.character_limit'));
        $this->assertSame('starter', $response->json('subscription.tier'));
    }

    public function test_admin_usage_returns_null_subscription_when_no_active_account(): void
    {
        $admin = User::factory()->create(['role' => UserRole::SuperAdmin, 'site_id' => null]);
        // No TtsAccount and no .env key: getResolvedApiKey() returns ''
        config(['tts.elevenlabs.api_key' => '']);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/integrations/elevenlabs/usage');

        $response->assertOk();
        $response->assertJsonPath('subscription', null);
        $this->assertContains($response->json('message'), ['No active ElevenLabs account.', 'Usage unavailable.']);
    }

    public function test_staff_cannot_fetch_usage(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        $response = $this->actingAs($staff)
            ->getJson('/api/admin/integrations/elevenlabs/usage');

        $response->assertForbidden();
    }
}
