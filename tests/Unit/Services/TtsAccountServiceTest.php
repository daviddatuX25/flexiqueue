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
        $this->assertSame($inactive->id, TtsAccount::getActive()?->id);
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
        $this->assertSame($account->id, TtsAccount::getActive()?->id);
    }
}
