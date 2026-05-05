<?php

declare(strict_types=1);

namespace Shipeasy\Tests;

use PHPUnit\Framework\TestCase;
use Shipeasy\Murmur3;

final class Murmur3Test extends TestCase
{
    public function testVectors(): void
    {
        // Values match the Ruby SDK reference impl across languages.
        $cases = [
            ['', 0x00000000],
            ['a', 0x3c2569b2],
            ['ab', 0x9bbfd75f],
            ['abc', 0xb3dd93fa],
            ['aaaa', 0x7eeed987],
            ['aaaaa', 0xe9ca302b],
            ['Hello, 世界', 0xe2a131eb],
            ['The quick brown fox jumps over the lazy dog', 0x2e4ff723],
        ];
        foreach ($cases as [$in, $expected]) {
            $this->assertSame($expected, Murmur3::hash32($in), "input: $in");
        }
    }
}
