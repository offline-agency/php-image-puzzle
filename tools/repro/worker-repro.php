<?php

declare(strict_types=1);

/*
 * Phase 0 subprocess reproduction for G08 (child exceptions escape) and G09
 * (signal deaths reported as success). Run against the original sources:
 * `php tools/repro/worker-repro.php`.
 */

require __DIR__ . '/../../vendor/autoload.php';

use ImagePuzzle\Exporter;
use ImagePuzzle\ExportOptions;

error_reporting(E_ALL & ~E_DEPRECATED);

$tmp = sys_get_temp_dir() . '/image-puzzle-worker-repro-' . getmypid();
@mkdir($tmp, 0777, true);
$src = $tmp . '/src.png';
$img = imagecreatetruecolor(40, 40);
imagepng($img, $src);
$parent = getmypid();
$sentinel = $tmp . '/post-catch.log';

// G08: a throwing callback in the worker returns into the caller's catch block in the child.
try {
    (new Exporter())->exportParallel($src, $tmp . '/g08', 2, 2, (new ExportOptions())->withOnPieceBeforeSave(
        static function (): void {
            throw new RuntimeException('callback failure');
        }
    ), 2);
} catch (Throwable $e) {
    file_put_contents($sentinel, getmypid() . "\n", FILE_APPEND);
    if (getmypid() !== $parent) {
        exit(0);
    }
}
$pids = array_filter(explode("\n", (string) @file_get_contents($sentinel)));
$foreign = array_filter($pids, static fn (string $pid): bool => (int) $pid !== $parent);
printf("G08  %-15s post-catch sentinel written by %d process(es), %d of them child PIDs\n", $foreign !== [] ? 'REPRODUCED' : 'NOT-REPRODUCED', count($pids), count($foreign));

// G09: a worker killed by a signal is not reported as a failure.
$result = null;
try {
    $result = (new Exporter())->exportParallel($src, $tmp . '/g09', 2, 2, (new ExportOptions())->withOnPieceBeforeSave(
        static function () use ($parent): void {
            if (getmypid() !== $parent) {
                posix_kill(getmypid(), SIGKILL);
            }
        }
    ), 2);
} catch (Throwable $e) {
    echo 'G09  NOT-REPRODUCED  parent raised ' . get_class($e) . "\n";
}
if ($result !== null) {
    printf("G09  REPRODUCED      parent reported success with %d manifest pieces after SIGKILL of every worker\n", count($result->getManifest()['pieces']));
}
exec('rm -rf ' . escapeshellarg($tmp));
