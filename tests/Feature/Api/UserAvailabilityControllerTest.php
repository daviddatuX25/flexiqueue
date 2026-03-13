<?php

namespace Tests\Feature\Api;

use App\Events\StaffAvailabilityUpdated;
use App\Models\Program;
use App\Models\Station;
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

    /** Per flexiqueue-wrx: display board gets real-time staff availability via broadcast. A.5: only when user has assigned station (program-scoped channel). */
    public function test_update_dispatches_staff_availability_updated_broadcast_when_user_has_assigned_station(): void
    {
        Event::fake([StaffAvailabilityUpdated::class]);

        $creator = User::factory()->create();
        $program = Program::create([
            'name' => 'Test Program',
            'description' => null,
            'is_active' => true,
            'created_by' => $creator->id,
        ]);
        $station = Station::create([
            'program_id' => $program->id,
            'name' => 'Desk A',
            'capacity' => 1,
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'name' => 'Jane Doe',
            'availability_status' => 'offline',
            'assigned_station_id' => $station->id,
        ]);

        $this->actingAs($user)->patchJson('/api/users/me/availability', [
            'status' => 'on_break',
        ]);

        Event::assertDispatched(StaffAvailabilityUpdated::class, function (StaffAvailabilityUpdated $event) use ($user, $program) {
            return $event->programId === $program->id
                && $event->userId === $user->id
                && $event->availabilityStatus === 'on_break'
                && $event->name === 'Jane Doe';
        });
    }

    /** A.5: when user has no assigned station, StaffAvailabilityUpdated is not broadcast (no program-scoped channel). */
    public function test_update_does_not_dispatch_staff_availability_broadcast_when_user_has_no_station(): void
    {
        Event::fake([StaffAvailabilityUpdated::class]);

        $user = User::factory()->create([
            'name' => 'Admin User',
            'availability_status' => 'offline',
            'assigned_station_id' => null,
        ]);

        $this->actingAs($user)->patchJson('/api/users/me/availability', [
            'status' => 'available',
        ]);

        Event::assertNotDispatched(StaffAvailabilityUpdated::class);
    }
}
