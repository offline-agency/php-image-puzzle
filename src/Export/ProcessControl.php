<?php

declare(strict_types=1);

namespace ImagePuzzle\Export;

/**
 * Process primitives used by the parallel exporter. The default
 * implementation wraps ext-pcntl/ext-posix; tests inject failures through it.
 */
interface ProcessControl
{
    public function isAvailable(): bool;

    /**
     * @return int child pid in the parent, 0 in the child, -1 on failure
     */
    public function fork(): int;

    /**
     * Wait for a specific child.
     *
     * @return array{pid: int, status: int, interrupted: bool}
     */
    public function wait(int $pid): array;

    public function signal(int $pid, int $signal): bool;

    public function exitedNormally(int $status): bool;

    public function exitCode(int $status): int;

    public function termSignal(int $status): ?int;

    /**
     * Terminate the current (child) process immediately with $code, without
     * returning to the caller and without running the application's shutdown
     * functions or destructors where the platform allows it.
     */
    public function terminateChild(int $code): never;
}
