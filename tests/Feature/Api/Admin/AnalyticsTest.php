<?php

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::factory()->admin()->create([
            'password' => Hash::make('secret'),
        ]);
    }

    public function test_analytics_summary_returns_ok_with_filters(): void
    {
        $admin = $this->createAdmin();
        $response = $this->actingAs($admin)
            ->getJson('/api/admin/analytics/summary?from=2025-01-01&to=2025-01-31');

        $response->assertOk();
        $response->assertJsonStructure([
            'total_clients_served',
            'median_wait_minutes',
            'p90_wait_minutes',
            'completion_rate',
            'active_sessions',
            'trend_total',
            'trend_median_wait',
            'trend_completion_rate',
        ]);
    }

    public function test_analytics_throughput_returns_array(): void
    {
        $admin = $this->createAdmin();
        $response = $this->actingAs($admin)
            ->getJson('/api/admin/analytics/throughput?from=2025-01-01&to=2025-01-31');

        $response->assertOk();
        $this->assertIsArray($response->json());
    }

    public function test_analytics_token_tts_health_returns_ok(): void
    {
        $admin = $this->createAdmin();
        $response = $this->actingAs($admin)->getJson('/api/admin/analytics/token-tts-health');

        $response->assertOk();
        $response->assertJsonStructure(['by_status', 'by_tts_status']);
    }

    public function test_non_admin_cannot_access_analytics(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)
            ->getJson('/api/admin/analytics/summary?from=2025-01-01&to=2025-01-31')
            ->assertForbidden();
    }
}
