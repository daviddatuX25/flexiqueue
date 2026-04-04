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
}
