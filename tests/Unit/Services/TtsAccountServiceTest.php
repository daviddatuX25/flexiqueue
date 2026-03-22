<?php

namespace Tests\Unit\Services;

use App\Models\TtsAccount;
use App\Services\TtsAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for TtsAccountService. Per docs/REFACTORING-ISSUE-LIST.md Issue 10: setActive().
 */
class TtsAccountServiceTest extends TestCase
{
    use RefreshDatabase;

    private TtsAccountService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TtsAccountService::class);
    }

    public function test_set_active_activates_given_account_and_deactivates_others(): void
    {
        $active = TtsAccount::create([
            'label' => 'First',
            'api_key' => '',
            'model_id' => 'eleven_multilingual_v2',
            'is_active' => true,
        ]);
        $inactive = TtsAccount::create([
            'label' => 'Second',
            'api_key' => '',
            'model_id' => 'eleven_multilingual_v2',
            'is_active' => false,
        ]);

        $this->service->setActive($inactive);

        $this->assertTrue($inactive->fresh()->is_active);
        $this->assertFalse($active->fresh()->is_active);
        $this->assertSame($inactive->id, TtsAccount::getActiveForProvider('elevenlabs')?->id);
    }

    public function test_set_active_when_only_one_account_succeeds(): void
    {
        $account = TtsAccount::create([
            'label' => 'Only',
            'api_key' => '',
            'model_id' => 'eleven_multilingual_v2',
            'is_active' => false,
        ]);

        $this->service->setActive($account);

        $this->assertTrue($account->fresh()->is_active);
        $this->assertSame($account->id, TtsAccount::getActiveForProvider('elevenlabs')?->id);
    }

    public function test_set_active_does_not_deactivate_other_providers(): void
    {
        $elevenActive = TtsAccount::create([
            'label' => 'EL active',
            'provider' => 'elevenlabs',
            'api_key' => '',
            'model_id' => 'eleven_multilingual_v2',
            'is_active' => true,
        ]);
        $azureActive = TtsAccount::create([
            'label' => 'Azure active',
            'provider' => 'azure',
            'api_key' => '',
            'model_id' => 'azure-model',
            'is_active' => true,
        ]);
        $elevenInactive = TtsAccount::create([
            'label' => 'EL inactive',
            'provider' => 'elevenlabs',
            'api_key' => '',
            'model_id' => 'eleven_multilingual_v2',
            'is_active' => false,
        ]);

        $this->service->setActive($elevenInactive);

        $this->assertTrue($elevenInactive->fresh()->is_active);
        $this->assertFalse($elevenActive->fresh()->is_active);
        $this->assertTrue($azureActive->fresh()->is_active);
        $this->assertSame($elevenInactive->id, TtsAccount::getActiveForProvider('elevenlabs')?->id);
        $this->assertSame($azureActive->id, TtsAccount::getActiveForProvider('azure')?->id);
    }
}
