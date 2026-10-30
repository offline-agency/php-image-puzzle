<?php

declare(strict_types=1);

use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Game\Prng;

test('matches the reference mulberry32 stream computed in JavaScript', function (): void {
    // Values produced by the reference JS implementation (Math.imul, >>> 0).
    $rng = new Prng(42, 'test');
    $values = [];
    for ($i = 0; $i < 6; $i++) {
        $values[] = $rng->nextUint32();
    }
    expect($values)->toBe([2350607806, 285177247, 1856135397, 2684595571, 3956451259, 2154350800]);
    expect($rng->int(1000))->toBe(620);
});

test('FNV-1a matches the published test vectors', function (): void {
    expect(Prng::fnv1a(''))->toBe(0x811C9DC5);
    expect(Prng::fnv1a('a'))->toBe(0xE40C292C);
    expect(Prng::fnv1a('foobar'))->toBe(0xBF9CF968);
});

test('streams are independent of each other and of call order elsewhere', function (): void {
    $a = new Prng(7, 'one');
    $b = new Prng(7, 'two');
    expect($a->nextUint32())->not->toBe($b->nextUint32());
    $fresh = new Prng(7, 'one');
    $fresh->nextUint32();
    expect((new Prng(7, 'one'))->int(50))->toBe((new Prng(7, 'one'))->int(50));
});

test('int stays in range and shuffle returns a permutation', function (): void {
    $rng = new Prng(3, 'range');
    for ($i = 0; $i < 2000; $i++) {
        $n = 1 + $i % 37;
        expect($rng->int($n))->toBeGreaterThanOrEqual(0)->toBeLessThan($n);
    }
    $shuffled = (new Prng(3, 'shuffle'))->shuffle(range(0, 49));
    expect($shuffled)->not->toBe(range(0, 49));
    $sorted = $shuffled;
    sort($sorted);
    expect($sorted)->toBe(range(0, 49));
    expect(array_is_list($shuffled))->toBeTrue();
});

test('seed and range limits are enforced', function (): void {
    expect(fn () => new Prng(-1, 'x'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new Prng(Prng::MAX_SEED + 1, 'x'))->toThrow(InvalidArgumentException::class);
    expect(fn () => (new Prng(1, 'x'))->int(0))->toThrow(InvalidArgumentException::class);
    expect(fn () => (new Prng(1, 'x'))->int(Prng::MAX_RANGE + 1))->toThrow(InvalidArgumentException::class);
    expect((new Prng(Prng::MAX_SEED, 'x'))->int(Prng::MAX_RANGE))->toBeLessThan(Prng::MAX_RANGE);
});
