#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Builds a self-contained demo site: one puzzle per game mode from a single
 * picture, the player script and an index page with a mode menu. Copy the
 * output directory to any static host (or serve it with php -S) to show the
 * library with your own image.
 *
 * Usage: php examples/demo/build.php <output-dir> [image] [--title=TEXT]
 *            [--credit=TEXT] [--preset=easy|medium|hard] [--seed=N]
 *
 * Without an image it uses docs/images/showcase/source.png, which
 * examples/showcase.php draws (no third-party content). The output directory
 * must be new or empty. Definitions are fetched, so open the site over HTTP,
 * not from file://; for a single offline file use `image-puzzle bundle`.
 */

use ImagePuzzle\Exception\ImagePuzzleException;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\Modes;

// The repository checkout, or vendor/offline-agency/image-puzzle in a project.
foreach ([dirname(__DIR__, 2) . '/vendor/autoload.php', dirname(__DIR__, 4) . '/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}
if (!class_exists(DefinitionBuilder::class)) {
    fwrite(STDERR, "Run composer install first.\n");
    exit(1);
}

const DEMO_LABELS = [
    'sliding' => 'Sliding',
    'swap' => 'Swap',
    'rotation' => 'Rotation',
    'strips' => 'Strips',
    'jigsaw' => 'Jigsaw',
    'nonogram' => 'Nonogram',
    'memory' => 'Memory',
    'tangram' => 'Tangram',
];

/**
 * @param list<string> $argv
 *
 * @return array{out: string, image: string, title: string, credit: string, preset: string, seed: int}
 */
function demoOptions(array $argv): array
{
    $root = dirname(__DIR__, 2);
    $positional = [];
    $options = ['title' => 'Image puzzles', 'credit' => '', 'preset' => 'easy', 'seed' => '1'];
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--(title|credit|preset|seed)=(.*)$/s', $argument, $match) === 1) {
            $options[$match[1]] = $match[2];
        } elseif (str_starts_with($argument, '--')) {
            throw new InvalidArgumentException("Unknown option {$argument}");
        } else {
            $positional[] = $argument;
        }
    }
    if ($positional === [] || count($positional) > 2) {
        throw new InvalidArgumentException('Usage: php examples/demo/build.php <output-dir> [image] [--title=TEXT] [--credit=TEXT] [--preset=easy|medium|hard] [--seed=N]');
    }
    $default = $root . '/docs/images/showcase/source.png';
    $image = $positional[1] ?? $default;
    if (!is_file($image)) {
        throw new InvalidArgumentException($image === $default ? 'Pass an image: the default picture is only in the repository checkout.' : "Image not found: {$image}");
    }
    if (!in_array($options['preset'], ['easy', 'medium', 'hard'], true)) {
        throw new InvalidArgumentException('--preset must be easy, medium or hard');
    }
    if (preg_match('/^\d{1,9}$/', $options['seed']) !== 1) {
        throw new InvalidArgumentException('--seed must be a non-negative integer');
    }
    $credit = $options['credit'];
    if ($credit === '' && $image === $default) {
        $credit = 'Picture drawn by examples/showcase.php in offline-agency/php-image-puzzle.';
    }

    return ['out' => $positional[0], 'image' => $image, 'title' => $options['title'], 'credit' => $credit, 'preset' => $options['preset'], 'seed' => (int) $options['seed']];
}

/**
 * @param list<string> $modes
 */
function demoPage(string $title, string $credit, array $modes): string
{
    $e = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $items = '';
    foreach ($modes as $mode) {
        $items .= "\n          <li><a href=\"?mode={$e($mode)}\" data-mode=\"{$e($mode)}\">{$e(DEMO_LABELS[$mode])}</a></li>";
    }
    $json = json_encode($modes, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $creditHtml = $credit === '' ? '' : "\n      <p class=\"credit\">{$e($credit)}</p>";

    return <<<HTML
        <!doctype html>
        <html lang="en">
          <head>
            <meta charset="utf-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <title>{$e($title)}</title>
            <style>
              :root { color-scheme: light dark; font-family: system-ui, sans-serif; }
              body { margin: 0 auto; padding: 1rem; max-width: 60rem; }
              nav ul { display: flex; flex-wrap: wrap; gap: 0.5rem; list-style: none; padding: 0; }
              nav a { display: inline-block; padding: 0.5rem 0.9rem; border: 1px solid currentColor; border-radius: 999px; color: inherit; text-decoration: none; }
              nav a[aria-current="page"] { background: CanvasText; color: Canvas; }
              nav a:focus-visible { outline: 3px solid Highlight; outline-offset: 2px; }
              .credit, .help { font-size: 0.875rem; opacity: 0.8; }
            </style>
            <script src="image-puzzle-player.iife.js"></script>
          </head>
          <body>
            <header>
              <h1>{$e($title)}</h1>
              <nav aria-label="Game modes">
                <ul>{$items}
                </ul>
              </nav>
            </header>
            <main>
              <image-puzzle-player id="game" persist></image-puzzle-player>
              <noscript>The puzzles need JavaScript.</noscript>
              <p class="help">Play with a mouse, touch or the keyboard: Tab reaches the board, arrows move, Enter acts, Escape cancels. Progress is kept in this browser.</p>
            </main>
            <footer>{$creditHtml}
              <p class="credit">Built with <a href="https://github.com/offline-agency/php-image-puzzle">offline-agency/image-puzzle</a>.</p>
            </footer>
            <script>
              const modes = {$json};
              const requested = new URLSearchParams(location.search).get('mode');
              const mode = modes.includes(requested) ? requested : modes[0];
              document.getElementById('game').setAttribute('src', mode + '/definition.json');
              for (const link of document.querySelectorAll('nav a')) {
                if (link.dataset.mode === mode) link.setAttribute('aria-current', 'page');
              }
            </script>
          </body>
        </html>

        HTML;
}

try {
    $arguments = [];
    foreach (is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [] as $argument) {
        if (is_string($argument)) {
            $arguments[] = $argument;
        }
    }
    $options = demoOptions($arguments);
    $out = rtrim($options['out'], '/');
    $existing = is_dir($out) ? scandir($out) : [];
    if ($existing === false || array_diff($existing, ['.', '..']) !== [] || (file_exists($out) && !is_dir($out))) {
        throw new InvalidArgumentException("The output directory must be new or empty: {$out}");
    }
    if (!is_dir($out) && !mkdir($out, 0o755, true) && !is_dir($out)) {
        throw new RuntimeException("Cannot create {$out}");
    }

    $builder = new DefinitionBuilder();
    $built = [];
    foreach (Modes::available() as $kind) {
        try {
            $result = $builder->build($options['image'], "{$out}/{$kind->value}", $kind, $options['preset'], seed: $options['seed']);
            $built[] = $kind->value;
            fwrite(STDOUT, sprintf("%-9s %s\n", $kind->value, $result->definition->id));
        } catch (ImagePuzzleException $e) {
            // Some pictures cannot make every mode (a nonogram needs a clear, unique picture).
            fwrite(STDERR, "{$kind->value}: skipped: {$e->getMessage()}\n");
        }
    }
    if ($built === []) {
        throw new RuntimeException('No mode could be built from this image.');
    }

    $player = dirname(__DIR__, 2) . '/resources/dist/image-puzzle-player.iife.js';
    if (!copy($player, "{$out}/image-puzzle-player.iife.js")) {
        throw new RuntimeException("Cannot copy {$player}");
    }
    file_put_contents("{$out}/index.html", demoPage($options['title'], $options['credit'], $built));
    fwrite(STDOUT, "Demo written to {$out}/index.html (" . count($built) . ' of ' . count(GameKind::cases()) . " modes). Serve it over HTTP, e.g. php -S 127.0.0.1:8000 -t {$out}\n");
} catch (InvalidArgumentException|RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
