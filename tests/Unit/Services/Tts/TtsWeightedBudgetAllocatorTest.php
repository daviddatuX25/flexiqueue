<?php

namespace Tests\Unit\Services\Tts;

use App\Services\Tts\TtsWeightedBudgetAllocator;
use PHPUnit\Framework\TestCase;

class TtsWeightedBudgetAllocatorTest extends TestCase
{
    public function test_splits_pool_by_weights(): void
    {
        $out = TtsWeightedBudgetAllocator::allocate(100, [1, 2], [1 => 1, 2 => 3]);

        $this->assertSame(25, $out[1]);
        $this->assertSame(75, $out[2]);
        $this->assertSame(100, $out[1] + $out[2]);
    }

    public function test_equal_split_when_zero_total_weight(): void
    {
        $out = TtsWeightedBudgetAllocator::allocate(10, [1, 2], []);

        $this->assertSame(5, $out[1]);
        $this->assertSame(5, $out[2]);
    }
}
