<?php

namespace Tests\Feature\Edge;

use App\Models\Program;
use App\Models\Site;
use App\Services\ProgramPackageExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EdgePackageVersioningTest extends TestCase
{
    use RefreshDatabase;

    private function makeProgramAndSite(): array
    {
        $site = Site::factory()->create();
        $program = Program::factory()->create(['site_id' => $site->id]);
        return [$program, $site];
    }

    /** @test */
    public function compute_package_version_returns_sha256_string(): void
    {
        [$program, $site] = $this->makeProgramAndSite();
        $exporter = app(ProgramPackageExporter::class);

        $version = $exporter->computePackageVersion($program, $site);

        $this->assertIsString($version);
        $this->assertSame(64, strlen($version));
    }

    /** @test */
    public function compute_package_version_is_stable_for_same_data(): void
    {
        [$program, $site] = $this->makeProgramAndSite();
        $exporter = app(ProgramPackageExporter::class);

        $v1 = $exporter->computePackageVersion($program, $site);
        $v2 = $exporter->computePackageVersion($program, $site);

        $this->assertSame($v1, $v2);
    }

    /** @test */
    public function export_manifest_includes_package_version_matching_compute(): void
    {
        [$program, $site] = $this->makeProgramAndSite();
        $exporter = app(ProgramPackageExporter::class);

        $package = $exporter->export($program, $site);
        $computed = $exporter->computePackageVersion($program, $site);

        $this->assertArrayHasKey('package_version', $package['manifest']);
        $this->assertSame($computed, $package['manifest']['package_version']);
    }

    /** @test */
    public function compute_package_version_changes_when_program_data_changes(): void
    {
        [$program, $site] = $this->makeProgramAndSite();
        $exporter = app(ProgramPackageExporter::class);

        $v1 = $exporter->computePackageVersion($program, $site);

        $program->update(['name' => 'Updated Program Name']);

        $v2 = $exporter->computePackageVersion($program, $site);

        $this->assertNotSame($v1, $v2, 'Package version must change when program data changes');
    }

    /** @test */
    public function import_service_stores_package_version_in_json(): void
    {
        $this->markTestSkipped('Requires HTTP mock setup — covered by EdgeBatchSyncTest pattern.');
    }

    /** @test */
    public function import_status_endpoint_returns_package_version(): void
    {
        config(['app.mode' => 'edge']);

        \Illuminate\Support\Facades\Storage::disk('local')->put(
            'edge_package_imported.json',
            json_encode([
                'status'          => 'complete',
                'imported_at'     => now()->toIso8601String(),
                'program_id'      => 1,
                'site_id'         => 1,
                'manifest_hash'   => str_repeat('a', 64),
                'package_version' => str_repeat('b', 64),
                'sync_tokens'     => false,
                'sync_clients'    => false,
                'sync_tts'        => false,
                'tts_asset_contract_version'    => 2,
                'tts_asset_references_count'    => 0,
            ])
        );

        $user = \App\Models\User::factory()->admin()->create();
        $this->actingAs($user)
            ->getJson('/api/admin/edge/import/status')
            ->assertOk()
            ->assertJsonFragment(['package_version' => str_repeat('b', 64)]);
    }

    /** @test */
    public function assignment_poll_returns_package_version_when_assigned(): void
    {
        $site    = \App\Models\Site::factory()->create();
        $program = \App\Models\Program::factory()->create(['site_id' => $site->id]);
        $plainToken = \Illuminate\Support\Str::random(64);
        $device  = \App\Models\EdgeDevice::create([
            'site_id'                 => $site->id,
            'name'                    => 'Test Pi',
            'device_token_hash'       => hash('sha256', $plainToken),
            'id_offset'               => 10_000_000,
            'sync_mode'               => 'auto',
            'supervisor_admin_access' => false,
            'assigned_program_id'     => $program->id,
            'session_active'          => false,
            'paired_at'               => now(),
        ]);

        $response = $this->withToken($plainToken)
            ->getJson('/api/edge/assignment')
            ->assertOk();

        $data = $response->json();

        $this->assertArrayHasKey('package_version', $data);
        $this->assertIsString($data['package_version']);
        $this->assertSame(64, strlen($data['package_version']));
    }

    /** @test */
    public function assignment_poll_omits_package_version_when_not_assigned(): void
    {
        $site   = \App\Models\Site::factory()->create();
        $plainToken = \Illuminate\Support\Str::random(64);
        $device = \App\Models\EdgeDevice::create([
            'site_id'                 => $site->id,
            'name'                    => 'Test Pi',
            'device_token_hash'       => hash('sha256', $plainToken),
            'id_offset'               => 10_000_000,
            'sync_mode'               => 'auto',
            'supervisor_admin_access' => false,
            'assigned_program_id'     => null,
            'session_active'          => false,
            'paired_at'               => now(),
        ]);

        $response = $this->withToken($plainToken)
            ->getJson('/api/edge/assignment')
            ->assertOk()
            ->assertJson(['assigned' => false]);

        $data = $response->json();
        $this->assertArrayNotHasKey('package_version', $data);
    }

    /** @test */
    public function heartbeat_returns_package_stale_true_when_version_mismatch(): void
    {
        $site    = \App\Models\Site::factory()->create();
        $program = \App\Models\Program::factory()->create(['site_id' => $site->id]);
        $plainToken = \Illuminate\Support\Str::random(64);
        $device  = \App\Models\EdgeDevice::create([
            'site_id'                 => $site->id,
            'name'                    => 'Test Pi',
            'device_token_hash'       => hash('sha256', $plainToken),
            'id_offset'               => 10_000_000,
            'sync_mode'               => 'auto',
            'supervisor_admin_access' => false,
            'assigned_program_id'     => $program->id,
            'session_active'          => false,
            'paired_at'               => now(),
        ]);

        $this->withToken($plainToken)
            ->postJson('/api/edge/heartbeat', [
                'session_active'  => false,
                'sync_mode'       => 'auto',
                'last_synced_at'  => null,
                'package_version' => 'outdated-version-hash',
                'app_version'     => null,
            ])
            ->assertOk()
            ->assertJsonFragment(['package_stale' => true]);
    }

    /** @test */
    public function heartbeat_returns_package_stale_false_when_version_matches(): void
    {
        $site    = \App\Models\Site::factory()->create();
        $program = \App\Models\Program::factory()->create(['site_id' => $site->id]);
        $plainToken = \Illuminate\Support\Str::random(64);
        $device  = \App\Models\EdgeDevice::create([
            'site_id'                 => $site->id,
            'name'                    => 'Test Pi',
            'device_token_hash'       => hash('sha256', $plainToken),
            'id_offset'               => 10_000_000,
            'sync_mode'               => 'auto',
            'supervisor_admin_access' => false,
            'assigned_program_id'     => $program->id,
            'session_active'          => false,
            'paired_at'               => now(),
        ]);

        $currentVersion = app(\App\Services\ProgramPackageExporter::class)
            ->computePackageVersion($program, $site);

        $this->withToken($plainToken)
            ->postJson('/api/edge/heartbeat', [
                'session_active'  => false,
                'sync_mode'       => 'auto',
                'last_synced_at'  => null,
                'package_version' => $currentVersion,
                'app_version'     => null,
            ])
            ->assertOk()
            ->assertJsonFragment(['package_stale' => false]);
    }

    /** @test */
    public function heartbeat_returns_package_stale_false_when_no_program_assigned(): void
    {
        $site   = \App\Models\Site::factory()->create();
        $plainToken = \Illuminate\Support\Str::random(64);
        $device = \App\Models\EdgeDevice::create([
            'site_id'                 => $site->id,
            'name'                    => 'Test Pi',
            'device_token_hash'       => hash('sha256', $plainToken),
            'id_offset'               => 10_000_000,
            'sync_mode'               => 'auto',
            'supervisor_admin_access' => false,
            'assigned_program_id'     => null,
            'session_active'          => false,
            'paired_at'               => now(),
        ]);

        $this->withToken($plainToken)
            ->postJson('/api/edge/heartbeat', [
                'session_active'  => false,
                'sync_mode'       => 'auto',
                'last_synced_at'  => null,
                'package_version' => null,
                'app_version'     => null,
            ])
            ->assertOk()
            ->assertJsonFragment(['package_stale' => false]);
    }

    /** @test */
    public function heartbeat_command_dispatches_import_when_stale_and_waiting(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $state = \App\Models\EdgeDeviceState::firstOrCreate(
            ['id' => 1],
            ['sync_mode' => 'auto', 'supervisor_admin_access' => false, 'session_active' => false]
        );
        $state->update([
            'paired_at'          => now(),
            'device_token'       => 'test-token',
            'central_url'        => 'http://central.test',
            'session_active'     => false,
            'active_program_id'  => 99,
            'sync_mode'          => 'auto',
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'http://central.test/api/edge/heartbeat' => \Illuminate\Support\Facades\Http::response([
                'revoked'       => false,
                'package_stale' => true,
                'sync_mode'     => 'auto',
            ], 200),
        ]);

        config(['app.mode' => 'edge']);
        config(['app.central_api_key' => 'test-api-key']);

        $this->artisan('edge:heartbeat')->assertSuccessful();

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ImportProgramPackageJob::class, function ($job) {
            return $job->programId === 99;
        });
    }

    /** @test */
    public function heartbeat_command_does_not_dispatch_import_when_stale_but_session_active(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $state = \App\Models\EdgeDeviceState::firstOrCreate(
            ['id' => 1],
            ['sync_mode' => 'auto', 'supervisor_admin_access' => false, 'session_active' => false]
        );
        $state->update([
            'paired_at'         => now(),
            'device_token'      => 'test-token',
            'central_url'       => 'http://central.test',
            'session_active'    => true,
            'active_program_id' => 99,
            'sync_mode'         => 'auto',
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'http://central.test/api/edge/heartbeat' => \Illuminate\Support\Facades\Http::response([
                'revoked'       => false,
                'package_stale' => true,
                'sync_mode'     => 'auto',
            ], 200),
        ]);

        config(['app.mode' => 'edge']);
        config(['app.central_api_key' => 'test-api-key']);

        $this->artisan('edge:heartbeat')->assertSuccessful();

        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\ImportProgramPackageJob::class);
    }

    /** @test */
    public function heartbeat_command_stores_package_stale_in_device_state(): void
    {
        $state = \App\Models\EdgeDeviceState::firstOrCreate(
            ['id' => 1],
            ['sync_mode' => 'auto', 'supervisor_admin_access' => false, 'session_active' => false]
        );
        $state->update([
            'paired_at'      => now(),
            'device_token'   => 'test-token',
            'central_url'    => 'http://central.test',
            'session_active' => true,
            'sync_mode'      => 'auto',
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'http://central.test/api/edge/heartbeat' => \Illuminate\Support\Facades\Http::response([
                'revoked'       => false,
                'package_stale' => true,
                'sync_mode'     => 'auto',
            ], 200),
        ]);

        config(['app.mode' => 'edge']);

        $this->artisan('edge:heartbeat')->assertSuccessful();

        $this->assertTrue((bool) \App\Models\EdgeDeviceState::current()->package_stale);
    }
}
