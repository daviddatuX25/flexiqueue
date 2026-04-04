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
}
