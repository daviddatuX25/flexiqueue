<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per staff-availability-status plan: PATCH /api/users/me/availability.
 * Auth: authenticated.
 */
class UserAvailabilityControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_availability_returns_200_and_updates_status(): void
    {
        $user = User::factory()->create([
            'availability_status' => 'offline',
        ]);

        $response = $this->actingAs($user)->patchJson('/api/users/me/availability', [
            'status' => 'available',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('availability_status', 'available');
        $response->assertJsonStructure(['availability_status', 'availability_updated_at']);

        $user->refresh();
        $this->assertSame('available', $user->availability_status);
    }

    public function test_update_accepts_on_break(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patchJson('/api/users/me/availability', [
            'status' => 'on_break',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('availability_status', 'on_break');
    }

    public function test_update_accepts_away(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patchJson('/api/users/me/availability', [
            'status' => 'away',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('availability_status', 'away');
    }

    public function test_update_returns_422_for_invalid_status(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patchJson('/api/users/me/availability', [
            'status' => 'invalid',
        ]);

        $response->assertStatus(422);
    }

    public function test_update_returns_401_when_unauthenticated(): void
    {
        $response = $this->patchJson('/api/users/me/availability', [
            'status' => 'available',
        ]);

        $response->assertStatus(401);
    }
}
