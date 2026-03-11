<?php

namespace Tests\Unit\Repositories;

use App\Models\TokenTtsSetting;
use App\Repositories\TokenTtsSettingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per REFACTORING-ISSUE-LIST.md Issue 8: getInstance() returns or creates singleton TokenTtsSetting.
 */
class TokenTtsSettingRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private TokenTtsSettingRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(TokenTtsSettingRepository::class);
    }

    public function test_get_instance_creates_row_with_defaults_when_none_exists(): void
    {
        $this->assertDatabaseCount('token_tts_settings', 0);

        $settings = $this->repository->getInstance();

        $this->assertInstanceOf(TokenTtsSetting::class, $settings);
        $this->assertDatabaseCount('token_tts_settings', 1);
        $this->assertNull($settings->voice_id);
        $this->assertSame(0.84, round($settings->rate, 2));
    }

    public function test_get_instance_uses_config_default_rate_when_creating(): void
    {
        $this->app['config']->set('tts.default_rate', 1.0);
        $this->assertDatabaseCount('token_tts_settings', 0);

        $settings = $this->repository->getInstance();

        $this->assertSame(1.0, $settings->rate);
    }

    public function test_get_instance_returns_existing_row_when_one_exists(): void
    {
        $existing = TokenTtsSetting::create([
            'voice_id' => 'custom-voice',
            'rate' => 1.25,
        ]);

        $settings = $this->repository->getInstance();

        $this->assertInstanceOf(TokenTtsSetting::class, $settings);
        $this->assertSame($existing->id, $settings->id);
        $this->assertSame('custom-voice', $settings->voice_id);
        $this->assertSame(1.25, $settings->rate);
    }
}
