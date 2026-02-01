<?php

declare(strict_types=1);

use ImagePuzzle\PieceContext;

test('constructor and getters', function (): void {
    $context = new PieceContext(1, 2, 10, 20, 16, 12, 100, 80);

    expect($context->getRow())->toBe(1);
    expect($context->getCol())->toBe(2);
    expect($context->getX())->toBe(10);
    expect($context->getY())->toBe(20);
    expect($context->getWidth())->toBe(16);
    expect($context->getHeight())->toBe(12);
    expect($context->getSourceWidth())->toBe(100);
    expect($context->getSourceHeight())->toBe(80);
});

test('edge piece context', function (): void {
    $context = new PieceContext(0, 0, 0, 0, 5, 5, 10, 10);
    expect($context->getRow())->toBe(0);
    expect($context->getCol())->toBe(0);
    expect($context->getX())->toBe(0);
    expect($context->getY())->toBe(0);
});
