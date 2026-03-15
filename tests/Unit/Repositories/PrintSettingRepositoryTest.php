<?php

namespace Tests\Unit\Repositories;

use App\Models\PrintSetting;
use App\Models\Site;
use App\Repositories\PrintSettingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Per REFACTORING-ISSUE-LIST.md Issue 8: getInstance() returns or creates PrintSetting.
 * Per site-scoping-migration-spec §4: getInstance(?int $siteId) — one row per site; null = legacy first/create.
 */
class PrintSettingRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private PrintSettingRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(PrintSettingRepository::class);
    }

    public function test_get_instance_with_null_creates_row_with_defaults_when_none_exists(): void
    {
        $this->assertDatabaseCount('print_settings', 0);

        $settings = $this->repository->getInstance(null);

        $this->assertInstanceOf(PrintSetting::class, $settings);
        $this->assertDatabaseCount('print_settings', 1);
        $this->assertSame(6, $settings->cards_per_page);
        $this->assertSame('a4', $settings->paper);
        $this->assertSame('portrait', $settings->orientation);
        $this->assertTrue($settings->show_hint);
        $this->assertTrue($settings->show_cut_lines);
    }

    public function test_get_instance_with_null_returns_existing_row_when_one_exists(): void
    {
        $existing = PrintSetting::create([
            'site_id' => null,
            'cards_per_page' => 8,
            'paper' => 'letter',
            'orientation' => 'landscape',
            'show_hint' => false,
            'show_cut_lines' => false,
        ]);

        $settings = $this->repository->getInstance(null);

        $this->assertInstanceOf(PrintSetting::class, $settings);
        $this->assertSame($existing->id, $settings->id);
        $this->assertSame(8, $settings->cards_per_page);
    }

    public function test_get_instance_with_site_id_creates_per_site_and_returns_same_row(): void
    {
        $site = Site::create([
            'name' => 'Test',
            'slug' => 'test',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $this->assertDatabaseCount('print_settings', 0);

        $settings1 = $this->repository->getInstance($site->id);
        $this->assertDatabaseCount('print_settings', 1);
        $this->assertSame($site->id, $settings1->site_id);
        $this->assertSame(6, $settings1->cards_per_page);

        $settings2 = $this->repository->getInstance($site->id);
        $this->assertSame($settings1->id, $settings2->id);
        $this->assertDatabaseCount('print_settings', 1);
    }

    public function test_get_instance_with_different_site_ids_returns_different_rows(): void
    {
        $siteA = Site::create([
            'name' => 'Site A',
            'slug' => 'site-a',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);
        $siteB = Site::create([
            'name' => 'Site B',
            'slug' => 'site-b',
            'api_key_hash' => \Illuminate\Support\Facades\Hash::make(Str::random(40)),
            'settings' => [],
            'edge_settings' => [],
        ]);

        $settingsA = $this->repository->getInstance($siteA->id);
        $settingsB = $this->repository->getInstance($siteB->id);

        $this->assertNotSame($settingsA->id, $settingsB->id);
        $this->assertSame($siteA->id, $settingsA->site_id);
        $this->assertSame($siteB->id, $settingsB->site_id);
        $this->assertDatabaseCount('print_settings', 2);
    }
}
