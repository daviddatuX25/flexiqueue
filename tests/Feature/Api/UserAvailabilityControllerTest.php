<?php

namespace Tests\Feature\Api;

use App\Events\StaffAvailabilityUpdated;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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

    /** Per ISSUES-ELABORATION §23: availability changes are logged to staff_activity_log. */
    public function test_update_logs_availability_change_to_staff_activity_log(): void
    {
        $user = User::factory()->create([
            'availability_status' => 'offline',
        ]);

        $this->actingAs($user)->patchJson('/api/users/me/availability', [
            'status' => 'available',
        ]);

        $this->assertDatabaseHas('staff_activity_log', [
            'user_id' => $user->id,
            'action_type' => 'availability_change',
            'old_value' => 'offline',
            'new_value' => 'available',
        ]);
    }

    /** Per flexiqueue-wrx: display board gets real-time staff availability via broadcast. */
    public function test_update_dispatches_staff_availability_updated_broadcast(): void
    {
        Event::fake([StaffAvailabilityUpdated::class]);

        $user = User::factory()->create([
            'name' => 'Jane Doe',
            'availability_status' => 'offline',
        ]);

        $this->actingAs($user)->patchJson('/api/users/me/availability', [
            'status' => 'on_break',
        ]);

        Event::assertDispatched(StaffAvailabilityUpdated::class, function (StaffAvailabilityUpdated $event) use ($user) {
            return $event->userId === $user->id
                && $event->availabilityStatus === 'on_break'
                && $event->name === 'Jane Doe';
        });
    }
}
