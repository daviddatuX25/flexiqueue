<?php

namespace Tests\Unit\Support;

use App\Support\ClientCategory;
use PHPUnit\Framework\TestCase;

class ClientCategoryTest extends TestCase
{
    public function test_normalize_returns_canonical_for_combined_value(): void
    {
        $this->assertSame('PWD / Senior / Pregnant', ClientCategory::normalize('PWD / Senior / Pregnant'));
        $this->assertSame('PWD / Senior / Pregnant', ClientCategory::normalize('pwd / senior / pregnant'));
    }

    public function test_normalize_returns_canonical_for_individual_priority_values(): void
    {
        $this->assertSame('PWD', ClientCategory::normalize('PWD'));
        $this->assertSame('PWD', ClientCategory::normalize('pwd'));
        $this->assertSame('Senior', ClientCategory::normalize('Senior'));
        $this->assertSame('Senior', ClientCategory::normalize('senior'));
        $this->assertSame('Pregnant', ClientCategory::normalize('Pregnant'));
        $this->assertSame('Pregnant', ClientCategory::normalize('pregnant'));
    }

    public function test_normalize_returns_canonical_for_regular_and_incomplete(): void
    {
        $this->assertSame('Regular', ClientCategory::normalize('Regular'));
        $this->assertSame('Regular', ClientCategory::normalize('regular'));
        $this->assertSame('Incomplete Documents', ClientCategory::normalize('Incomplete Documents'));
        $this->assertSame('Incomplete Documents', ClientCategory::normalize('incomplete documents'));
    }

    public function test_normalize_returns_null_for_null_and_empty(): void
    {
        $this->assertNull(ClientCategory::normalize(null));
        $this->assertNull(ClientCategory::normalize(''));
        $this->assertNull(ClientCategory::normalize('   '));
    }

    public function test_normalize_passes_through_unknown_values_trimmed(): void
    {
        $this->assertSame('Veteran', ClientCategory::normalize('  Veteran  '));
        $this->assertSame('Custom', ClientCategory::normalize('Custom'));
    }

    public function test_is_priority_returns_true_for_priority_categories(): void
    {
        $this->assertTrue(ClientCategory::isPriority('PWD'));
        $this->assertTrue(ClientCategory::isPriority('pwd'));
        $this->assertTrue(ClientCategory::isPriority('Senior'));
        $this->assertTrue(ClientCategory::isPriority('senior'));
        $this->assertTrue(ClientCategory::isPriority('Pregnant'));
        $this->assertTrue(ClientCategory::isPriority('pregnant'));
        $this->assertTrue(ClientCategory::isPriority('PWD / Senior / Pregnant'));
        $this->assertTrue(ClientCategory::isPriority('pwd / senior / pregnant'));
    }

    public function test_is_priority_returns_false_for_non_priority(): void
    {
        $this->assertFalse(ClientCategory::isPriority('Regular'));
        $this->assertFalse(ClientCategory::isPriority('Incomplete Documents'));
        $this->assertFalse(ClientCategory::isPriority('Veteran'));
        $this->assertFalse(ClientCategory::isPriority(null));
        $this->assertFalse(ClientCategory::isPriority(''));
    }

    public function test_is_priority_exact_match_does_not_match_substring(): void
    {
        $this->assertFalse(ClientCategory::isPriority('Not Pregnant'));
        $this->assertFalse(ClientCategory::isPriority('Non-PWD'));
    }
}
