<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per 08-API-SPEC-PHASE1 §1.3: POST /api/auth/verify-pin.
 */
class VerifyPinTest extends TestCase
{
    use RefreshDatabase;

    public function test_verify_pin_valid_returns_200(): void
    {
        $supervisor = User::factory()->supervisor()->withOverridePin('123456')->create();
        $staff = User::factory()->create();

        $response = $this->actingAs($staff)->postJson('/api/auth/verify-pin', [
            'user_id' => $supervisor->id,
            'pin' => '123456',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('verified', true);
        $response->assertJsonPath('user_id', $supervisor->id);
        $response->assertJsonPath('role', 'supervisor');
    }

    public function test_verify_pin_invalid_returns_401(): void
    {
        $supervisor = User::factory()->supervisor()->withOverridePin('123456')->create();
        $staff = User::factory()->create();

        $response = $this->actingAs($staff)->postJson('/api/auth/verify-pin', [
            'user_id' => $supervisor->id,
            'pin' => '999999',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('verified', false);
        $response->assertJsonPath('message', 'Invalid PIN.');
    }

    public function test_verify_pin_staff_without_pin_returns_401(): void
    {
        $staff = User::factory()->create(); // no override_pin
        $requester = User::factory()->create();

        $response = $this->actingAs($requester)->postJson('/api/auth/verify-pin', [
            'user_id' => $staff->id,
            'pin' => '123456',
        ]);

        $response->assertStatus(401);
    }

    public function test_verify_pin_validation_returns_422(): void
    {
        $staff = User::factory()->create();

        $response = $this->actingAs($staff)->postJson('/api/auth/verify-pin', [
            'user_id' => 99999, // non-existent
            'pin' => '123',
        ]);

        $response->assertStatus(422);
    }

    public function test_guest_cannot_verify_pin_returns_401(): void
    {
        $response = $this->postJson('/api/auth/verify-pin', [
            'user_id' => 1,
            'pin' => '123456',
        ]);

        $response->assertStatus(401);
    }
}
