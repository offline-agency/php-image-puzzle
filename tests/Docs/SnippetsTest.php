<?php

declare(strict_types=1);

use ImagePuzzle\Tests\Support\Fixtures;

/*
 * Every code block marked with <!-- snippet --> in the documentation runs
 * here, in document order, in one scratch directory per document that holds
 * photo.jpg and photo.png. PHP blocks get the autoloader prepended; bash
 * blocks may only call vendor/bin/image-puzzle (run as bin/image-puzzle).
 */

const SNIPPET_DOCUMENTS = ['README.md', 'docs/TUTORIAL.md', 'docs/API.md', 'docs/OPERATIONS.md', 'docs/MIGRATION.md'];

/**
 * @return list<array{lang: string, code: string, line: int}>
 */
function snippets(string $document): array
{
    $text = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $document);
    preg_match_all('/<!-- snippet -->\R```(php|bash)\R(.*?)\R```/s', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    $found = [];
    foreach ($matches as $match) {
        $found[] = ['lang' => $match[1][0], 'code' => $match[2][0], 'line' => substr_count(substr($text, 0, $match[0][1]), "\n") + 1];
    }

    return $found;
}

/**
 * @param list<string> $command
 *
 * @return array{0: int, 1: string}
 */
function runSnippetProcess(array $command, string $cwd): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start ' . implode(' ', $command));
    }
    $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

    return [proc_close($process), $output];
}

it('runs the documented snippets', function (string $document): void {
    $repo = dirname(__DIR__, 2);
    $list = snippets($document);
    expect($list)->not->toBe([], "{$document} has no <!-- snippet --> blocks");
    $dir = Fixtures::tempDir('ip-snippets-');
    try {
        $image = Fixtures::coordinateImage(320, 240, false);
        imagejpeg($image, $dir . '/photo.jpg', 90);
        imagepng($image, $dir . '/photo.png');
        foreach ($list as $index => $snippet) {
            $where = "{$document}:{$snippet['line']}";
            if ($snippet['lang'] === 'php') {
                $code = preg_replace(['/^<\?php\s*/', '/^require .*autoload\.php.;\R/m'], '', $snippet['code']);
                file_put_contents("{$dir}/snippet-{$index}.php", "<?php\n\nrequire " . var_export($repo . '/vendor/autoload.php', true) . ";\n\n{$code}\n");
                [$code, $output] = runSnippetProcess([PHP_BINARY, "snippet-{$index}.php"], $dir);
                expect($code)->toBe(0, "{$where} failed:\n{$output}");
                continue;
            }
            $lines = preg_split('/\R/', $snippet['code']);
            foreach ($lines === false ? [] : $lines as $line) {
                $line = trim((string) preg_replace('/\s+#.*$/', '', $line));
                if ($line === '') {
                    continue;
                }
                expect($line)->toStartWith('vendor/bin/image-puzzle ', "{$where}: only image-puzzle commands are run");
                if (str_starts_with($line, 'vendor/bin/image-puzzle bundle ') && !class_exists(ZipArchive::class)) {
                    continue; // Documented as needing ext-zip.
                }
                expect($line)->not->toMatch('/["\'\\\\$`;&|<>]/', "{$where}: keep snippet commands free of shell syntax");
                $args = preg_split('/\s+/', substr($line, strlen('vendor/bin/image-puzzle ')));
                [$code, $output] = runSnippetProcess([PHP_BINARY, $repo . '/bin/image-puzzle', ...($args === false ? [] : $args)], $dir);
                expect($code)->toBe(0, "{$where} `{$line}` failed:\n{$output}");
            }
        }
    } finally {
        Fixtures::removeDir($dir);
    }
})->with(SNIPPET_DOCUMENTS);
