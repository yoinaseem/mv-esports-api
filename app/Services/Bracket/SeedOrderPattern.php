<?php

namespace App\Services\Bracket;

/**
 * The standard recursive seed-order pattern used by single- and double-
 * elimination brackets. Maps a bracket position (0-indexed) to the seed
 * that should be placed there so top seeds meet only in later rounds.
 *
 * Examples:
 *   size 2:  [1, 2]
 *   size 4:  [1, 4, 2, 3]
 *   size 8:  [1, 8, 4, 5, 2, 7, 3, 6]
 *   size 16: [1, 16, 8, 9, 4, 13, 5, 12, 2, 15, 7, 10, 3, 14, 6, 11]
 *
 * Recursive shape: seedOrder(2n) interleaves seedOrder(n) with each value
 * paired against its complement (2n + 1 - value). Cited in DESIGN.md §8.
 */
class SeedOrderPattern
{
    /**
     * @return int[] seeds in bracket-position order (1-indexed seeds, 0-indexed positions).
     */
    public static function forSize(int $size): array
    {
        if ($size < 2 || ($size & ($size - 1)) !== 0) {
            throw new \InvalidArgumentException("Bracket size must be a power of two ≥ 2; got {$size}.");
        }

        if ($size === 2) {
            return [1, 2];
        }

        $smaller = self::forSize($size / 2);
        $result  = [];
        foreach ($smaller as $seed) {
            $result[] = $seed;
            $result[] = $size + 1 - $seed;
        }
        return $result;
    }

    /**
     * The smallest power of two that is ≥ $n.
     */
    public static function nextPowerOfTwo(int $n): int
    {
        if ($n < 1) {
            throw new \InvalidArgumentException("nextPowerOfTwo requires n ≥ 1; got {$n}.");
        }
        if (($n & ($n - 1)) === 0) {
            return $n; // already a power of two
        }
        return 1 << ((int) ceil(log($n, 2)));
    }

    /**
     * True iff $n is a power of two and ≥ 1.
     */
    public static function isPowerOfTwo(int $n): bool
    {
        return $n >= 1 && ($n & ($n - 1)) === 0;
    }
}
