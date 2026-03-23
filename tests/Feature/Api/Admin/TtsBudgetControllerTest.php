<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Site;
use App\Models\SiteTtsUsageRollup;
use App\Models\TtsPlatformBudget;
use App\Models\TtsSiteBudgetWeight;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TtsBudgetControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_get_own_site_budget(): void
    {
        $site = Site::query()->create([
            'name' => 'Test Site',
            'slug' => 'test-'.Str::random(6),
            'api_key_hash' => 'hash',
            'settings' => [
                'tts_budget' => [
                    'enabled' => true,
                    'mode' => 'chars',
                    'period' => 'monthly',
                    'limit' => 10000,
                    'block_on_limit' => false,
                ],
            ],
            'edge_settings' => [],
            'is_default' => true,
        ]);

        SiteTtsUsageRollup::create([
            'site_id' => $site->id,
            'period_key' => now()->format('Y-m'),
            'chars_used' => 2500,
            'generation_count' => 50,
        ]);

        $admin = User::factory()->admin()->create(['site_id' => $site->id]);

        $response = $this->actingAs($admin)->getJson('/api/admin/tts/budget');

        $response->assertOk();
        $response->assertJsonPath('platform_global_budget_enabled', false);
        $response->assertJson([
            'policy' => [
                'enabled' => true,
                'limit' => 10000,
                'block_on_limit' => false,
            ],
            'usage' => [
                'chars_used' => 2500,
            ],
            'remaining' => 7500,
            'at_limit' => false,
        ]);
    }

    public function test_admin_cannot_access_other_site_budget(): void
    {
        $siteA = Site::query()->create([
            'name' => 'Site A',
            'slug' => 'site-a-'.Str::random(6),
            'api_key_hash' => 'hash',
            'settings' => [],
            'edge_settings' => [],
            'is_default' => false,
        ]);

        $siteB = Site::query()->create([
            'name' => 'Site B',
            'slug' => 'site-b-'.Str::random(6),
            'api_key_hash' => 'hash2',
            'settings' => [],
            'edge_settings' => [],
            'is_default' => false,
        ]);

        $admin = User::factory()->admin()->create(['site_id' => $siteA->id]);

        $response = $this->actingAs($admin)->getJson("/api/admin/sites/{$siteB->id}/tts-budget");

        $response->assertStatus(404);
    }

    public function test_superadmin_can_access_any_site_budget(): void
    {
        $site = Site::query()->create([
            'name' => 'Test Site',
            'slug' => 'test-'.Str::random(6),
            'api_key_hash' => 'hash',
            'settings' => [
                'tts_budget' => [
                    'enabled' => true,
                    'limit' => 5000,
                ],
            ],
            'edge_settings' => [],
            'is_default' => true,
        ]);

        $superadmin = User::factory()->superAdmin()->create(['site_id' => null]);

        $response = $this->actingAs($superadmin)->getJson("/api/admin/sites/{$site->id}/tts-budget");

        $response->assertOk();
        $response->assertJsonPath('policy.enabled', true);
    }

    public function test_superadmin_can_fetch_all_sites_budgets(): void
    {
        $site = Site::query()->create([
            'name' => 'Budget Site',
            'slug' => 'budget-'.Str::random(6),
            'api_key_hash' => 'hash',
            'settings' => [
                'tts_budget' => [
                    'enabled' => true,
                    'limit' => 5000,
                    'period' => 'monthly',
                ],
            ],
            'edge_settings' => [],
            'is_default' => true,
        ]);

        SiteTtsUsageRollup::create([
            'site_id' => $site->id,
            'period_key' => now()->format('Y-m'),
            'chars_used' => 1000,
            'generation_count' => 20,
        ]);

        $superadmin = User::factory()->superAdmin()->create(['site_id' => null]);

        $response = $this->actingAs($superadmin)->getJson('/api/admin/tts/budgets');

        $response->assertOk();
        $response->assertJsonStructure(['budgets' => [['site_id', 'site_name', 'slug', 'policy', 'usage', 'remaining', 'at_limit']]]);
        $budgets = $response->json('budgets');
        $this->assertCount(1, $budgets);
        $this->assertSame((string) $site->id, (string) $budgets[0]['site_id']);
        $this->assertSame(1000, $budgets[0]['usage']['chars_used']);
    }

    public function test_admin_cannot_fetch_all_sites_budgets(): void
    {
        $site = Site::query()->create([
            'name' => 'Test',
            'slug' => 'test-'.Str::random(6),
            'api_key_hash' => 'hash',
            'settings' => [],
            'edge_settings' => [],
            'is_default' => true,
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);

        $response = $this->actingAs($admin)->getJson('/api/admin/tts/budgets');

        $response->assertForbidden();
    }

    public function test_budget_returns_null_usage_when_policy_not_enforced(): void
    {
        $site = Site::query()->create([
            'name' => 'Test Site',
            'slug' => 'test-'.Str::random(6),
            'api_key_hash' => 'hash',
            'settings' => [],
            'edge_settings' => [],
            'is_default' => true,
        ]);

        $admin = User::factory()->admin()->create(['site_id' => $site->id]);

        $response = $this->actingAs($admin)->getJson('/api/admin/tts/budget');

        $response->assertOk();
        $response->assertJsonPath('platform_global_budget_enabled', false);
        $response->assertJson([
            'policy' => [
                'enabled' => false,
            ],
            'usage' => null,
            'remaining' => null,
        ]);
    }

    public function test_admin_budget_includes_global_monitoring_when_platform_global_budget_enforced(): void
    {
        $site = Site::query()->create([
            'name' => 'Global Budget Site',
            'slug' => 'gb-'.Str::random(6),
            'api_key_hash' => 'hash',
            'settings' => [
                'tts_budget' => [
                    'enabled' => true,
                    'mode' => 'chars',
                    'period' => 'monthly',
                    'limit' => 99999,
                    'block_on_limit' => false,
                ],
            ],
            'edge_settings' => [],
            'is_default' => true,
        ]);

        TtsSiteBudgetWeight::query()->create([
            'site_id' => $site->id,
            'weight' => 1,
        ]);

        $platform = TtsPlatformBudget::settings();
        $platform->global_enabled = true;
        $platform->period = 'monthly';
        $platform->char_limit = 10000;
        $platform->block_on_limit = true;
        $platform->warning_threshold_pct = 80;
        $platform->save();

        $periodKey = now()->format('Y-m');
        SiteTtsUsageRollup::create([
            'site_id' => $site->id,
            'period_key' => $periodKey,
            'chars_used' => 1000,
            'generation_count' => 10,
        ]);

        $admin = User::factory()->admin()->create(['site_id' => $site->id]);

        $response = $this->actingAs($admin)->getJson('/api/admin/tts/budget');

        $response->assertOk();
        $response->assertJsonPath('platform_global_budget_enabled', true);
        $response->assertJsonPath('global_monitoring.effective_char_limit', 10000);
        $response->assertJsonPath('global_monitoring.chars_used', 1000);
        $response->assertJsonPath('global_monitoring.remaining', 9000);
        $response->assertJsonPath('global_monitoring.platform_char_limit', 10000);
        $response->assertJsonPath('usage.chars_used', 1000);
        $response->assertJsonPath('remaining', 9000);
    }

    public function test_site_budget_policy_mode_is_normalized_to_chars(): void
    {
        $site = Site::query()->create([
            'name' => 'Legacy Mode Site',
            'slug' => 'legacy-mode-'.Str::random(6),
            'api_key_hash' => 'hash',
            'settings' => [
                'tts_budget' => [
                    'enabled' => true,
                    'mode' => 'minutes',
                    'period' => 'monthly',
                    'limit' => 1000,
                ],
            ],
            'edge_settings' => [],
            'is_default' => true,
        ]);

        $admin = User::factory()->admin()->create(['site_id' => $site->id]);

        $response = $this->actingAs($admin)->getJson('/api/admin/tts/budget');

        $response->assertOk();
        $response->assertJsonPath('policy.mode', 'chars');
    }
}
