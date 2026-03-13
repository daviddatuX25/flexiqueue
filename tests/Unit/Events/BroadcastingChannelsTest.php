<?php

namespace Tests\Unit\Events;

use App\Events\DisplaySettingsUpdated;
use App\Events\NowServing;
use App\Events\ProgramStatusChanged;
use App\Events\QueueLengthUpdated;
use App\Events\StaffAvailabilityUpdated;
use App\Events\StationActivity;
use Illuminate\Broadcasting\Channel;
use PHPUnit\Framework\TestCase;

/**
 * A.5: Assert broadcast channel names are program-scoped per central-edge-v2-final Phase A.
 * display.activity → display.activity.{programId}; global.queue → queue.{programId}.
 */
class BroadcastingChannelsTest extends TestCase
{
    public function test_display_settings_updated_broadcasts_on_display_activity_program_id(): void
    {
        $event = new DisplaySettingsUpdated(
            programId: 7,
            displayAudioMuted: false,
            displayAudioVolume: 1.0,
        );
        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(Channel::class, $channels[0]);
        $this->assertSame('display.activity.7', $channels[0]->name);
    }

    public function test_station_activity_broadcasts_on_display_activity_program_id_and_display_station(): void
    {
        $event = new StationActivity(
            programId: 3,
            stationId: 11,
            stationName: 'Desk A',
            message: 'Test',
            alias: 'A1',
            actionType: 'call',
            createdAt: now()->toIso8601String(),
        );
        $channels = $event->broadcastOn();
        $this->assertCount(2, $channels);
        $names = array_map(fn ($c) => $c->name, $channels);
        $this->assertContains('display.activity.3', $names);
        $this->assertContains('display.station.11', $names);
    }

    public function test_queue_length_updated_broadcasts_on_queue_program_id_and_display_station(): void
    {
        $event = new QueueLengthUpdated(programId: 5, stationId: 22);
        $channels = $event->broadcastOn();
        $this->assertCount(2, $channels);
        $names = array_map(fn ($c) => $c->name, $channels);
        $this->assertContains('queue.5', $names);
        $this->assertContains('display.station.22', $names);
    }

    public function test_now_serving_broadcasts_on_queue_program_id_and_display_station(): void
    {
        $event = new NowServing(programId: 2, stationId: 9, payload: ['session_id' => 1, 'alias' => 'A1']);
        $channels = $event->broadcastOn();
        $this->assertCount(2, $channels);
        $names = array_map(fn ($c) => $c->name, $channels);
        $this->assertContains('queue.2', $names);
        $this->assertContains('display.station.9', $names);
    }

    public function test_program_status_changed_broadcasts_on_display_activity_program_id(): void
    {
        $event = new ProgramStatusChanged(programId: 4, programIsPaused: true);
        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertSame('display.activity.4', $channels[0]->name);
    }

    public function test_staff_availability_updated_broadcasts_on_display_activity_program_id(): void
    {
        $event = new StaffAvailabilityUpdated(programId: 1, userId: 10, availabilityStatus: 'available', name: 'Jane');
        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertSame('display.activity.1', $channels[0]->name);
    }
}
