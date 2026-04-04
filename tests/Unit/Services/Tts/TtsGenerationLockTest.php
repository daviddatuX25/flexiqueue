<?php

namespace Tests\Unit\Services\Tts;

use App\Services\Tts\TtsGenerationLock;
use Tests\TestCase;

class TtsGenerationLockTest extends TestCase
{
    public function test_run_returns_true_when_lock_is_acquired(): void
    {
        $lock = app(TtsGenerationLock::class);
        $ran = false;

        $acquired = $lock->run('test-lock-key', function () use (&$ran): void {
            $ran = true;
        });

        $this->assertTrue($acquired);
        $this->assertTrue($ran);
    }
}
