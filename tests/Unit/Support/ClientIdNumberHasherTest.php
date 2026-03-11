<?php

namespace Tests\Unit\Support;

use App\Support\ClientIdNumberHasher;
use PHPUnit\Framework\TestCase;

class ClientIdNumberHasherTest extends TestCase
{
    public function test_same_input_produces_same_hash(): void
    {
        $hash1 = ClientIdNumberHasher::hash('PhilHealth', 'AB-1234');
        $hash2 = ClientIdNumberHasher::hash('PhilHealth', 'AB-1234');

        $this->assertSame($hash1, $hash2);
    }

    public function test_different_types_produce_different_hashes(): void
    {
        $hash1 = ClientIdNumberHasher::hash('PhilHealth', 'AB-1234');
        $hash2 = ClientIdNumberHasher::hash('SSS', 'AB-1234');

        $this->assertNotSame($hash1, $hash2);
    }

    public function test_normalization_ignores_case_whitespace_and_separators(): void
    {
        $base = ClientIdNumberHasher::hash('PhilHealth', 'AB1234');
        $withDashes = ClientIdNumberHasher::hash('PhilHealth', 'ab-1234');
        $withSpaces = ClientIdNumberHasher::hash('PhilHealth', '  a b 1 2 3 4  ');
        $withPunctuation = ClientIdNumberHasher::hash('PhilHealth', 'A.B/1-2_3 4');

        $this->assertSame($base, $withDashes);
        $this->assertSame($base, $withSpaces);
        $this->assertSame($base, $withPunctuation);
    }

    public function test_empty_after_normalization_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ClientIdNumberHasher::hash('PhilHealth', ' --- ___   ');
    }
}

