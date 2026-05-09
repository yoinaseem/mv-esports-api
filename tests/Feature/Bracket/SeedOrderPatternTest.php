<?php

use App\Services\Bracket\SeedOrderPattern;

test('size 2 returns the trivial pairing', function () {
    expect(SeedOrderPattern::forSize(2))->toBe([1, 2]);
});

test('size 4 returns the canonical seed-order', function () {
    expect(SeedOrderPattern::forSize(4))->toBe([1, 4, 2, 3]);
});

test('size 8 returns the canonical seed-order from DESIGN §8', function () {
    expect(SeedOrderPattern::forSize(8))->toBe([1, 8, 4, 5, 2, 7, 3, 6]);
});

test('size 16 returns the canonical seed-order', function () {
    expect(SeedOrderPattern::forSize(16))
        ->toBe([1, 16, 8, 9, 4, 13, 5, 12, 2, 15, 7, 10, 3, 14, 6, 11]);
});

test('size 32 produces an order where seed 1 and seed 32 are paired in round 1', function () {
    $order = SeedOrderPattern::forSize(32);
    expect($order[0])->toBe(1);
    expect($order[1])->toBe(32);
    expect(count($order))->toBe(32);
    expect(array_sum($order))->toBe(32 * 33 / 2); // contains every seed exactly once
});

test('non-power-of-two sizes are rejected', function () {
    expect(fn () => SeedOrderPattern::forSize(6))->toThrow(InvalidArgumentException::class);
    expect(fn () => SeedOrderPattern::forSize(10))->toThrow(InvalidArgumentException::class);
});

test('size 1 and size 0 are rejected', function () {
    expect(fn () => SeedOrderPattern::forSize(1))->toThrow(InvalidArgumentException::class);
    expect(fn () => SeedOrderPattern::forSize(0))->toThrow(InvalidArgumentException::class);
});

test('nextPowerOfTwo rounds up', function () {
    expect(SeedOrderPattern::nextPowerOfTwo(1))->toBe(1);
    expect(SeedOrderPattern::nextPowerOfTwo(2))->toBe(2);
    expect(SeedOrderPattern::nextPowerOfTwo(3))->toBe(4);
    expect(SeedOrderPattern::nextPowerOfTwo(5))->toBe(8);
    expect(SeedOrderPattern::nextPowerOfTwo(6))->toBe(8);
    expect(SeedOrderPattern::nextPowerOfTwo(8))->toBe(8);
    expect(SeedOrderPattern::nextPowerOfTwo(9))->toBe(16);
    expect(SeedOrderPattern::nextPowerOfTwo(17))->toBe(32);
});

test('isPowerOfTwo correctly identifies powers of two', function () {
    foreach ([1, 2, 4, 8, 16, 32, 64] as $p) {
        expect(SeedOrderPattern::isPowerOfTwo($p))->toBeTrue();
    }
    foreach ([0, 3, 5, 6, 7, 10, 15, 17] as $np) {
        expect(SeedOrderPattern::isPowerOfTwo($np))->toBeFalse();
    }
});
