<?php

namespace Tests\Unit\Support;

use App\Support\MobileNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Per PRIVACY-BY-DESIGN-IDENTITY-BINDING.md: normalization rules cannot change
 * after the first row is stored without a full re-hash migration.
 * A bug here corrupts every stored mobile_hash.
 */
class MobileNormalizerTest extends TestCase
{
    public function test_keep_as_is_format_produces_same_output(): void
    {
        $input = '09171234567';
        $this->assertSame('09171234567', MobileNormalizer::normalize($input));
    }

    public function test_plus_63_format_strips_and_prepends_zero(): void
    {
        $input = '+639171234567';
        $this->assertSame('09171234567', MobileNormalizer::normalize($input));
    }

    public function test_spaces_format_strips_spaces(): void
    {
        $input = '0917 123 4567';
        $this->assertSame('09171234567', MobileNormalizer::normalize($input));
    }

    public function test_dashes_format_strips_dashes(): void
    {
        $input = '0917-123-4567';
        $this->assertSame('09171234567', MobileNormalizer::normalize($input));
    }

    public function test_parens_and_dashes_format_strips_parens_and_dashes(): void
    {
        $input = '(0917) 123-4567';
        $this->assertSame('09171234567', MobileNormalizer::normalize($input));
    }

    public function test_all_formats_produce_identical_output(): void
    {
        $expected = '09171234567';
        $formats = [
            '09171234567',
            '+639171234567',
            '0917 123 4567',
            '0917-123-4567',
            '(0917) 123-4567',
        ];

        foreach ($formats as $input) {
            $this->assertSame($expected, MobileNormalizer::normalize($input), "Failed for input: {$input}");
        }
    }
}
