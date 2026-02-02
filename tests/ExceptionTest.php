<?php

declare(strict_types=1);

use ImagePuzzle\Exception\ExportFailedException;
use ImagePuzzle\Exception\GdNotAvailableException;
use ImagePuzzle\Exception\ImagePuzzleException;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\UnsupportedImageTypeException;

test('InvalidArgumentException extends InvalidArgumentException and implements ImagePuzzleException', function (): void {
    $e = new InvalidArgumentException('Rows must be at least 1.');
    expect($e)->toBeInstanceOf(\InvalidArgumentException::class);
    expect($e)->toBeInstanceOf(ImagePuzzleException::class);
    expect($e->getMessage())->toBe('Rows must be at least 1.');
});

test('ExportFailedException extends RuntimeException and implements ImagePuzzleException', function (): void {
    $e = new ExportFailedException('Failed to create directory: /tmp/foo');
    expect($e)->toBeInstanceOf(\RuntimeException::class);
    expect($e)->toBeInstanceOf(ImagePuzzleException::class);
    expect($e->getMessage())->toContain('Failed to create directory');
});

test('GdNotAvailableException extends RuntimeException and implements ImagePuzzleException', function (): void {
    $e = new GdNotAvailableException('The GD extension is required but not loaded.');
    expect($e)->toBeInstanceOf(\RuntimeException::class);
    expect($e)->toBeInstanceOf(ImagePuzzleException::class);
    expect($e->getMessage())->toContain('GD');
});

test('UnsupportedImageTypeException extends RuntimeException and implements ImagePuzzleException', function (): void {
    $e = new UnsupportedImageTypeException('Unsupported image type: BMP.');
    expect($e)->toBeInstanceOf(\RuntimeException::class);
    expect($e)->toBeInstanceOf(ImagePuzzleException::class);
    expect($e->getMessage())->toContain('Unsupported');
});

test('exceptions are throwable', function (): void {
    expect(fn () => throw new InvalidArgumentException('test'))
        ->toThrow(InvalidArgumentException::class, 'test');
    expect(fn () => throw new ExportFailedException('test'))
        ->toThrow(ExportFailedException::class, 'test');
    expect(fn () => throw new GdNotAvailableException('test'))
        ->toThrow(GdNotAvailableException::class, 'test');
    expect(fn () => throw new UnsupportedImageTypeException('test'))
        ->toThrow(UnsupportedImageTypeException::class, 'test');
});
