<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Site;
use App\Models\TtsPlatformBudget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TtsPlatformBudgetControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_get_platform_budget_dashboard(): void
    {
        $site = Site::query()->create([
            'name' => 'S1',
            'slug' => 's1-'.Str::random(6),
            'api_key_hash' => 'h',
            'settings' => [],
            'edge_settings' => [],
            'is_default' => true,
        ]);

        $super = User::factory()->superAdmin()->create(['site_id' => null]);

        $response = $this->actingAs($super)->getJson('/api/admin/tts/platform-budget');

        $response->assertOk();
        $response->assertJsonStructure([
            'global' => ['enabled', 'period', 'mode', 'char_limit', 'block_on_limit', 'warning_threshold_pct'],
            'global_enforced',
            'sites' => [['site_id', 'site_name', 'weight', 'chars_used']],
            'total_metered_chars_all_sites',
        ]);
        $this->assertCount(1, $response->json('sites'));
        $this->assertSame((int) $site->id, $response->json('sites.0.site_id'));
    }

    public function test_admin_cannot_get_platform_budget_dashboard(): void
    {
        $site = Site::query()->create([
            'name' => 'S1',
            'slug' => 's1-'.Str::random(6),
            'api_key_hash' => 'h',
            'settings' => [],
            'edge_settings' => [],
            'is_default' => true,
        ]);
        $admin = User::factory()->admin()->create(['site_id' => $site->id]);

        $response = $this->actingAs($admin)->getJson('/api/admin/tts/platform-budget');

        $response->assertForbidden();
    }

    public function test_superadmin_can_update_platform_budget_and_weights(): void
    {
        $site = Site::query()->create([
            'name' => 'S1',
            'slug' => 's1-'.Str::random(6),
            'api_key_hash' => 'h',
            'settings' => [],
            'edge_settings' => [],
            'is_default' => true,
        ]);

        $super = User::factory()->superAdmin()->create(['site_id' => null]);

        $response = $this->actingAs($super)->putJson('/api/admin/tts/platform-budget', [
            'global_enabled' => true,
            'period' => 'monthly',
            'char_limit' => 1000,
            'block_on_limit' => true,
            'warning_threshold_pct' => 80,
            'weights' => [
                (string) $site->id => 2,
            ],
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('global.enabled'));
        $this->assertSame(1000, $response->json('global.char_limit'));
        $this->assertSame(1000, $response->json('sites.0.effective_limit'));
    }

    public function test_superadmin_cannot_set_platform_budget_mode_to_minutes(): void
    {
        $super = User::factory()->superAdmin()->create(['site_id' => null]);

        $response = $this->actingAs($super)->putJson('/api/admin/tts/platform-budget', [
            'mode' => 'minutes',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['mode']);
    }

    public function test_platform_budget_mode_is_normalized_to_chars_in_dashboard_payload(): void
    {
        $budget = TtsPlatformBudget::settings();
        $budget->mode = 'minutes';
        $budget->save();

        $super = User::factory()->superAdmin()->create(['site_id' => null]);

        $response = $this->actingAs($super)->getJson('/api/admin/tts/platform-budget');

        $response->assertOk();
        $response->assertJsonPath('global.mode', 'chars');
    }
}
