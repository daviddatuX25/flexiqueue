<?php

namespace Tests\Unit\Repositories;

use App\Models\PrintSetting;
use App\Repositories\PrintSettingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per REFACTORING-ISSUE-LIST.md Issue 8: getInstance() returns or creates singleton PrintSetting.
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

    public function test_get_instance_creates_row_with_defaults_when_none_exists(): void
    {
        $this->assertDatabaseCount('print_settings', 0);

        $settings = $this->repository->getInstance();

        $this->assertInstanceOf(PrintSetting::class, $settings);
        $this->assertDatabaseCount('print_settings', 1);
        $this->assertSame(6, $settings->cards_per_page);
        $this->assertSame('a4', $settings->paper);
        $this->assertSame('portrait', $settings->orientation);
        $this->assertTrue($settings->show_hint);
        $this->assertTrue($settings->show_cut_lines);
    }

    public function test_get_instance_returns_existing_row_when_one_exists(): void
    {
        $existing = PrintSetting::create([
            'cards_per_page' => 8,
            'paper' => 'letter',
            'orientation' => 'landscape',
            'show_hint' => false,
            'show_cut_lines' => false,
        ]);

        $settings = $this->repository->getInstance();

        $this->assertInstanceOf(PrintSetting::class, $settings);
        $this->assertSame($existing->id, $settings->id);
        $this->assertSame(8, $settings->cards_per_page);
        $this->assertSame('letter', $settings->paper);
        $this->assertSame('landscape', $settings->orientation);
        $this->assertFalse($settings->show_hint);
        $this->assertFalse($settings->show_cut_lines);
    }
}
