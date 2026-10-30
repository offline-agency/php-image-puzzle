<?php

declare(strict_types=1);

namespace ImagePuzzle\Export;

/**
 * ext-pcntl implementation.
 *
 * terminateChild() replaces the child process image with "/bin/sh -c 'exit N'"
 * via pcntl_exec(), so no PHP shutdown function, destructor or output buffer of
 * the host application runs in the child. If exec is impossible it falls back
 * to exit(N).
 */
final class PcntlProcessControl implements ProcessControl
{
    public function isAvailable(): bool
    {
        // posix_kill is required to terminate started workers when a later
        // fork fails; without it parallel export falls back to sequential.
        return PHP_SAPI === 'cli' && function_exists('pcntl_fork') && function_exists('pcntl_waitpid') && function_exists('posix_kill');
    }

    public function fork(): int
    {
        return pcntl_fork();
    }

    public function wait(int $pid): array
    {
        $status = 0;
        $result = pcntl_waitpid($pid, $status);
        $interrupted = $result === -1 && pcntl_get_last_error() === PCNTL_EINTR;

        return ['pid' => $result, 'status' => is_int($status) ? $status : 0, 'interrupted' => $interrupted];
    }

    public function signal(int $pid, int $signal): bool
    {
        return function_exists('posix_kill') && posix_kill($pid, $signal);
    }

    public function exitedNormally(int $status): bool
    {
        return pcntl_wifexited($status);
    }

    public function exitCode(int $status): int
    {
        $code = pcntl_wexitstatus($status);

        return $code === false ? 0 : $code;
    }

    public function termSignal(int $status): ?int
    {
        if (!pcntl_wifsignaled($status)) {
            return null;
        }
        $signal = pcntl_wtermsig($status);

        return $signal === false || $signal === 0 ? null : $signal;
    }

    public function terminateChild(int $code): never
    {
        $code = max(0, min(255, $code));
        if (function_exists('pcntl_exec') && is_executable('/bin/sh')) {
            @pcntl_exec('/bin/sh', ['-c', 'exit ' . $code]);
        }
        exit($code);
    }
}
