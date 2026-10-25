<?php

declare(strict_types=1);

/*
 * Measures generation and export on synthetic images.
 *
 *   composer benchmark -- [--sizes=800x600,2000x1500] [--grid=6x8] [--workers=0,4]
 *                         [--styles=no_edge,jigsaw] [--samples=4] [--repeat=3] [--json=build/benchmark.json]
 *
 * Reported per case (median wall time of --repeat runs; memory maxima over
 * the runs): parent peak PHP memory (memory_get_peak_usage(true)) and, for
 * parallel exports, the sum over workers of the peak RSS and of the peak PHP
 * memory each worker reported before exiting (ExportResult::getWorkerMemory()).
 * getrusage(RUSAGE_CHILDREN) is not used: workers end through exec, which
 * resets the counter on some platforms. Numbers depend on the machine: publish
 * them only together with the environment block this tool prints.
 */

use ImagePuzzle\EdgeStyle\EdgeStyleFactory;
use ImagePuzzle\Exporter;
use ImagePuzzle\ExportOptions;
use ImagePuzzle\GeometryOptions;
use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\ResourceLimits;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * @return array<string, string>
 */
function benchmarkOptions(): array
{
    $defaults = ['sizes' => '800x600,2000x1500', 'grid' => '6x8', 'workers' => '0,4', 'styles' => 'no_edge,jigsaw', 'samples' => '4', 'repeat' => '3', 'json' => ''];
    $argv = $_SERVER['argv'] ?? [];
    foreach (is_array($argv) ? array_slice($argv, 1) : [] as $arg) {
        if (is_string($arg) && preg_match('/^--([a-z]+)=(.*)$/', $arg, $m) === 1 && array_key_exists($m[1], $defaults)) {
            $defaults[$m[1]] = $m[2];
        } elseif ($arg !== '--') {
            fwrite(STDERR, 'Unknown argument: ' . (is_string($arg) ? $arg : '?') . "\n");
            exit(2);
        }
    }

    return $defaults;
}

/**
 * @return array{0: int, 1: int}
 */
function pair(string $value, string $what): array
{
    if (preg_match('/^(\d+)x(\d+)$/', $value, $m) !== 1) {
        fwrite(STDERR, "Invalid {$what}: {$value} (expected AxB)\n");
        exit(2);
    }

    return [(int) $m[1], (int) $m[2]];
}

function writeSource(string $path, int $width, int $height): void
{
    if ($width < 1 || $height < 1) {
        throw new RuntimeException('Invalid size');
    }
    $image = imagecreatetruecolor($width, $height);
    for ($y = 0; $y < $height; $y += 8) {
        $color = imagecolorallocate($image, ($y * 7) % 256, ($y * 3) % 256, 128);
        if ($color === false) {
            throw new RuntimeException('Colour allocation failed');
        }
        imagefilledrectangle($image, 0, $y, $width - 1, min($height - 1, $y + 7), $color);
    }
    imagepng($image, $path, 1);
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }
    $entries = is_dir($path) ? scandir($path) : false;
    foreach ($entries === false ? [] : $entries as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    if (is_dir($path)) {
        rmdir($path);
    }
}

/**
 * @param list<float> $values
 */
function median(array $values): float
{
    sort($values);
    $n = count($values);
    if ($n === 0) {
        return 0.0;
    }

    return $n % 2 === 1 ? $values[intdiv($n, 2)] : ($values[intdiv($n, 2) - 1] + $values[intdiv($n, 2)]) / 2;
}

$options = benchmarkOptions();
[$rows, $cols] = pair($options['grid'], 'grid');
$repeat = max(1, (int) $options['repeat']);
$samples = (int) $options['samples'];
$tmp = sys_get_temp_dir() . '/image-puzzle-bench-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);

$environment = [
    'php' => PHP_VERSION,
    'os' => PHP_OS_FAMILY . ' ' . php_uname('r') . ' ' . php_uname('m'),
    'gd' => is_string(gd_info()['GD Version'] ?? null) ? gd_info()['GD Version'] : 'unknown',
    'pcntl' => function_exists('pcntl_fork'),
    'opcache' => function_exists('opcache_get_status') && is_array(@opcache_get_status(false)),
    'date' => gmdate('c'),
];
echo "Environment\n";
foreach ($environment as $key => $value) {
    echo sprintf("  %-8s %s\n", $key, is_bool($value) ? ($value ? 'yes' : 'no') : $value);
}
echo "\n" . sprintf("%-11s %-8s %-8s %8s %10s %12s %16s %16s\n", 'size', 'style', 'workers', 'pieces', 'wall s', 'parent MiB', 'sum worker RSS', 'sum worker PHP');

$results = [];
try {
    foreach (explode(',', $options['sizes']) as $size) {
        [$width, $height] = pair($size, 'size');
        $source = "{$tmp}/source-{$width}x{$height}.png";
        writeSource($source, $width, $height);
        foreach (explode(',', $options['styles']) as $styleName) {
            foreach (array_map('intval', explode(',', $options['workers'])) as $workers) {
                $walls = [];
                $parentPeak = 0;
                $workerRss = 0;
                $workerPhp = 0;
                $workerCount = 0;
                $pieces = 0;
                for ($run = 0; $run < $repeat; $run++) {
                    $out = "{$tmp}/out-{$run}";
                    $style = EdgeStyleFactory::fromEdge(['type' => $styleName]);
                    $geometry = new GeometryOptions(1, $samples);
                    memory_reset_peak_usage();
                    $start = hrtime(true);
                    if ($workers > 1) {
                        $result = (new Exporter())->exportParallel($source, $out, $rows, $cols, new ExportOptions(), $workers, $style, null, $geometry, new ResourceLimits());
                    } else {
                        $result = PuzzleGenerator::generateAndExport($source, $out, $rows, $cols, $style, new ExportOptions(), geometry: $geometry);
                    }
                    $walls[] = (hrtime(true) - $start) / 1e9;
                    $parentPeak = max($parentPeak, memory_get_peak_usage(true));
                    $memory = $result->getWorkerMemory();
                    $workerCount = count($memory);
                    $workerRss = max($workerRss, array_sum(array_column($memory, 'maxRss')));
                    $workerPhp = max($workerPhp, array_sum(array_column($memory, 'peakMemory')));
                    $pieces = count($result->getFiles()) - 1;
                    removeTree($out);
                }
                $row = [
                    'size' => "{$width}x{$height}",
                    'style' => $styleName,
                    'workersRequested' => $workers,
                    'workersUsed' => $workerCount,
                    'pieces' => $pieces,
                    'wallSecondsMedian' => round(median($walls), 4),
                    'parentPeakPhpBytes' => $parentPeak,
                    'workerRssSumBytes' => $workerCount > 0 ? $workerRss : null,
                    'workerPhpPeakSumBytes' => $workerCount > 0 ? $workerPhp : null,
                ];
                $results[] = $row;
                $mib = static fn (?int $bytes): string => $bytes === null ? '-' : sprintf('%.1F', $bytes / 1048576);
                echo sprintf("%-11s %-8s %-8s %8d %10.3F %12s %16s %16s\n", $row['size'], $styleName, "{$workerCount}/{$workers}", $pieces, $row['wallSecondsMedian'], $mib($parentPeak), $mib($row['workerRssSumBytes']), $mib($row['workerPhpPeakSumBytes']));
            }
        }
    }
} finally {
    removeTree($tmp);
}

if ($options['json'] !== '') {
    $dir = dirname($options['json']);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($options['json'], json_encode(['environment' => $environment, 'grid' => "{$rows}x{$cols}", 'samples' => $samples, 'repeat' => $repeat, 'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    echo "\nWrote {$options['json']}\n";
}
