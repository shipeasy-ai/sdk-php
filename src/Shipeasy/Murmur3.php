<?php

declare(strict_types=1);

namespace Shipeasy;

final class Murmur3
{
    private const C1 = 0xcc9e2d51;
    private const C2 = 0x1b873593;
    private const MASK = 0xFFFFFFFF;

    private static function mul32(int $a, int $b): int
    {
        // 32-bit truncating multiply, safe on 64-bit PHP.
        $aHi = ($a >> 16) & 0xFFFF; $aLo = $a & 0xFFFF;
        $bHi = ($b >> 16) & 0xFFFF; $bLo = $b & 0xFFFF;
        return (((($aHi * $bLo) + ($aLo * $bHi)) << 16) + ($aLo * $bLo)) & self::MASK;
    }

    private static function rotl(int $x, int $r): int
    {
        $x &= self::MASK;
        return (($x << $r) | ($x >> (32 - $r))) & self::MASK;
    }

    public static function hash32(string $key): int
    {
        $n = strlen($key);
        $h1 = 0;
        $nblocks = intdiv($n, 4);

        for ($i = 0; $i < $nblocks; $i++) {
            $off = $i * 4;
            $k1 = ord($key[$off])
                | (ord($key[$off + 1]) << 8)
                | (ord($key[$off + 2]) << 16)
                | (ord($key[$off + 3]) << 24);
            $k1 = self::mul32($k1, self::C1);
            $k1 = self::rotl($k1, 15);
            $k1 = self::mul32($k1, self::C2);
            $h1 ^= $k1;
            $h1 = self::rotl($h1, 13);
            $h1 = (self::mul32($h1, 5) + 0xe6546b64) & self::MASK;
        }

        $tail = $nblocks * 4;
        $k1 = 0;
        $rem = $n & 3;
        if ($rem >= 3) $k1 ^= ord($key[$tail + 2]) << 16;
        if ($rem >= 2) $k1 ^= ord($key[$tail + 1]) << 8;
        if ($rem >= 1) {
            $k1 ^= ord($key[$tail]);
            $k1 = self::mul32($k1, self::C1);
            $k1 = self::rotl($k1, 15);
            $k1 = self::mul32($k1, self::C2);
            $h1 ^= $k1;
        }

        $h1 ^= $n;
        $h1 ^= ($h1 >> 16) & self::MASK;
        $h1 = self::mul32($h1, 0x85ebca6b);
        $h1 ^= ($h1 >> 13) & self::MASK;
        $h1 = self::mul32($h1, 0xc2b2ae35);
        $h1 ^= ($h1 >> 16) & self::MASK;
        return $h1 & self::MASK;
    }
}
