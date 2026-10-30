<?php

declare(strict_types=1);

namespace ImagePuzzle\Cli;

use Composer\InstalledVersions;
use ImagePuzzle\Batch\BatchEvent;
use ImagePuzzle\Batch\BatchManifest;
use ImagePuzzle\Batch\BatchOptions;
use ImagePuzzle\Batch\BatchResult;
use ImagePuzzle\Batch\BatchRunner;
use ImagePuzzle\Batch\CancellationToken;
use ImagePuzzle\Batch\ItemResult;
use ImagePuzzle\Cache\AssetCache;
use ImagePuzzle\Exception\CapabilityUnavailableException;
use ImagePuzzle\Exception\GdNotAvailableException;
use ImagePuzzle\Exception\ImagePuzzleException;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Exception\ResourceLimitExceededException;
use ImagePuzzle\Exception\UnsupportedImageTypeException;
use ImagePuzzle\Export\BundleExporter;
use ImagePuzzle\Export\ImageEncoder;
use ImagePuzzle\Export\PdfExporter;
use ImagePuzzle\Export\PdfOptions;
use ImagePuzzle\Export\Reconstruction;
use ImagePuzzle\Export\SvgExporter;
use ImagePuzzle\Export\SvgOptions;
use ImagePuzzle\ExportOptions;
use ImagePuzzle\Game\AssetOptions;
use ImagePuzzle\Game\BuildConfiguration;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\Modes;
use ImagePuzzle\Game\Presets;
use ImagePuzzle\Game\PuzzleDirectory;
use ImagePuzzle\ImageLoader;
use ImagePuzzle\ResourceLimits;
use ImagePuzzle\Support\ErrorCapture;

/**
 * The image-puzzle command (bin/image-puzzle). Every command writes new
 * files only (existing targets are errors) and maps failures to exit codes:
 *
 *   0 success, 1 failure while writing, 2 usage error, 3 invalid input
 *   (image, definition, configuration or option value), 4 missing
 *   capability (GD format, ext-zip), 5 resource limit exceeded.
 */
final class Application
{
    public const EXIT_OK = 0;
    public const EXIT_FAILURE = 1;
    public const EXIT_USAGE = 2;
    public const EXIT_INVALID = 3;
    public const EXIT_CAPABILITY = 4;
    public const EXIT_LIMIT = 5;

    private const COMMANDS = [
        'generate' => 'Build a playable puzzle (definition.json and assets) from an image.',
        'validate' => 'Check a game definition (file or puzzle directory).',
        'inspect' => 'Describe a built puzzle and check the files it references.',
        'reconstruct' => 'Rebuild the solved board from the puzzle files as a PNG.',
        'svg' => 'Export the puzzle as SVG with vector piece outlines.',
        'pdf' => 'Export a printable PDF (A4/A3, cut lines, reference page).',
        'bundle' => 'Package the puzzle as a self-contained, offline ZIP.',
        'batch' => 'Build many puzzles from a batch file, with resume, cache and cancellation.',
        'capabilities' => 'Show which image formats and optional features are available.',
        'help' => 'Show help for a command.',
    ];

    private const HELP = [
        'generate' => <<<'TXT'
            Usage: image-puzzle generate <image> <output-dir> [options]

            Builds definition.json and its assets into <output-dir>, which must be
            new or empty.

              --kind=KIND            jigsaw (default), sliding, swap, rotation, strips,
                                     nonogram, memory or tangram
              --preset=NAME          easy, medium (default), hard or custom
              --rows=N, --cols=N     custom grid size (implies --preset=custom)
              --param=KEY=VALUE      other custom parameter; VALUE is JSON when it
                                     parses as JSON (e.g. --param=orientations=[0,90]),
                                     otherwise a string. Repeatable.
              --seed=N               deterministic seed (default 0)
              --config=FILE          versioned build configuration JSON
                                     (image-puzzle/build-config v1) instead of
                                     --kind/--preset/--rows/--cols/--param/--seed
              --format=FORMAT        png (default), jpeg or webp for generated images
              --quality=N            encoder quality (png 0-9, jpeg/webp 0-100)
              --resolutions=LIST     jigsaw only: extra piece sets, comma-separated
                                     names (thumbnail, mobile, desktop) or name:width
              --atlas=NAME           jigsaw only: pack "full" pieces or a resolution
                                     into one atlas image
              --dry-run              validate and print the plan; write nothing
              --json                 print the result as JSON
            TXT,
        'validate' => <<<'TXT'
            Usage: image-puzzle validate <definition.json|puzzle-dir> [--json]

            Parses and validates the definition (schema rules, mode rules and ID).
            Exit code 3 when it is invalid.
            TXT,
        'inspect' => <<<'TXT'
            Usage: image-puzzle inspect <puzzle-dir> [--json]

            Prints the kind, board, tiles and media of a built puzzle and checks
            that every referenced file exists inside the directory.
            TXT,
        'reconstruct' => <<<'TXT'
            Usage: image-puzzle reconstruct <puzzle-dir> <output.png>

            Draws every piece at its solved position (grid modes: the board crop).
            TXT,
        'svg' => <<<'TXT'
            Usage: image-puzzle svg <puzzle-dir> <output.svg> [options]

              --image=MODE           link (default, relative path), embed (data URI)
                                     or none (outlines only; implies --no-pieces)
              --no-pieces            do not draw the clipped picture
              --no-outlines          do not draw piece outlines
              --stroke=#RRGGBB       outline colour (default #000000)
              --stroke-width=N       outline width in source pixels (default 1)
              --width-mm=N           physical width (default: pixel size)
              --title=TEXT           SVG title
            TXT,
        'pdf' => <<<'TXT'
            Usage: image-puzzle pdf <puzzle-dir> <output.pdf> [options]

              --paper=a4|a3          paper size (default a4)
              --orientation=MODE     auto (default), portrait or landscape
              --width-mm=N           printed board width; larger than a sheet tiles
                                     the board over several sheets (default: fit)
              --margin-mm=N          sheet margin, 5-50 (default 10)
              --no-cut-lines         print the picture without cut lines
              --no-reference         leave out the solved reference page
              --max-dpi=N            image resolution limit (default 300)
              --title=TEXT           document and reference page title
              --dry-run              print the page layout; write nothing
              --json                 print the result as JSON
            TXT,
        'bundle' => <<<'TXT'
            Usage: image-puzzle bundle <puzzle-dir> <output.zip> [options]

              --folder=NAME          folder inside the archive (default image-puzzle)
              --title=TEXT           page title
              --dry-run              list the entries; write nothing
              --json                 print the result as JSON

            Needs ext-zip. Open index.html from the extracted folder (file:// works).
            TXT,
        'batch' => <<<'TXT'
            Usage: image-puzzle batch <batch.json> [options]

            Builds every item of an image-puzzle/batch v1 file into its own new or
            empty output directory. Relative paths are resolved against the batch
            file. A failed or cancelled item leaves no output behind.

              --concurrency=N        build up to N items at once in worker
                                     processes (1-32, default 1; needs ext-pcntl)
              --fail-fast            stop at the first failed item
              --checkpoint=FILE      record finished items; a rerun with the same
                                     file skips items whose output still verifies
              --cache=DIR            reuse identical builds from a local cache
              --cache-max-mb=N       cache size bound (default 1024)
              --cancel-file=FILE     cancel when FILE appears (Ctrl+C also cancels)
              --json                 print the result as JSON

            Progress goes to stderr. Exit code 0 only when every item is built;
            1 when the batch is partial, failed or cancelled.
            TXT,
        'capabilities' => <<<'TXT'
            Usage: image-puzzle capabilities [--json]
            TXT,
        'help' => <<<'TXT'
            Usage: image-puzzle help [command]
            TXT,
    ];

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    /**
     * @param resource|null $out
     * @param resource|null $err
     */
    public function __construct(
        $out = null,
        $err = null,
        private readonly ResourceLimits $limits = new ResourceLimits(),
    ) {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
    }

    /**
     * @param list<string> $argv arguments without the script name
     */
    public function run(array $argv): int
    {
        $command = $argv[0] ?? 'help';
        $rest = array_slice($argv, 1);
        try {
            return match ($command) {
                '--version', '-V' => $this->print('image-puzzle ' . self::version()),
                'help', '--help', '-h' => $this->help($rest),
                'generate' => $this->generate($rest),
                'validate' => $this->validate($rest),
                'inspect' => $this->inspect($rest),
                'reconstruct' => $this->reconstruct($rest),
                'svg' => $this->svg($rest),
                'pdf' => $this->pdf($rest),
                'bundle' => $this->bundle($rest),
                'batch' => $this->batch($rest),
                'capabilities' => $this->capabilities($rest),
                default => throw new UsageException("Unknown command \"{$command}\". Run \"image-puzzle help\" for the list."),
            };
        } catch (UsageException $e) {
            return $this->fail($e->getMessage(), self::EXIT_USAGE);
        } catch (InvalidArgumentException | InvalidDefinitionException | UnsupportedImageTypeException | \JsonException $e) {
            return $this->fail($e->getMessage(), self::EXIT_INVALID);
        } catch (CapabilityUnavailableException | GdNotAvailableException $e) {
            return $this->fail($e->getMessage(), self::EXIT_CAPABILITY);
        } catch (ResourceLimitExceededException $e) {
            return $this->fail($e->getMessage(), self::EXIT_LIMIT);
        } catch (ImagePuzzleException | \RuntimeException $e) {
            return $this->fail($e->getMessage(), self::EXIT_FAILURE);
        }
    }

    public static function version(): string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('offline-agency/image-puzzle')) {
            return (string) InstalledVersions::getPrettyVersion('offline-agency/image-puzzle');
        }

        return 'unknown';
    }

    /**
     * @param list<string> $argv
     */
    private function help(array $argv): int
    {
        $topic = $argv[0] ?? null;
        if ($topic !== null && $topic !== '--help') {
            if (!isset(self::HELP[$topic])) {
                throw new UsageException("Unknown command \"{$topic}\".");
            }

            return $this->print(self::HELP[$topic]);
        }
        $lines = ['image-puzzle ' . self::version(), '', 'Usage: image-puzzle <command> [arguments] [options]', '', 'Commands:'];
        foreach (self::COMMANDS as $name => $summary) {
            $lines[] = sprintf('  %-14s %s', $name, $summary);
        }
        $lines[] = '';
        $lines[] = 'Run "image-puzzle help <command>" for its options.';
        $lines[] = 'Exit codes: 0 ok, 1 failure, 2 usage, 3 invalid input, 4 missing capability, 5 resource limit.';

        return $this->print(implode("\n", $lines));
    }

    /**
     * @param list<string> $argv
     */
    private function generate(array $argv): int
    {
        if (in_array('--help', $argv, true)) {
            return $this->print(self::HELP['generate']);
        }
        $args = Arguments::parse(
            $argv,
            ['kind', 'preset', 'rows', 'cols', 'param', 'seed', 'config', 'format', 'quality', 'resolutions', 'atlas'],
            ['dry-run', 'json'],
            ['param'],
        );
        [$image, $output] = $args->expect(2, 2, 'generate <image> <output-dir> [options]');
        $config = $this->buildConfiguration($args);
        $assets = new AssetOptions(
            format: $args->choice('format', [ExportOptions::FORMAT_PNG, ExportOptions::FORMAT_JPEG, ExportOptions::FORMAT_WEBP]),
            quality: $args->int('quality', 0, 100),
            resolutions: self::resolutions($args->value('resolutions')),
            atlas: $args->value('atlas'),
        );

        if ($args->flag('dry-run')) {
            $plan = $this->plan($image, $output, $config, $assets);

            return $args->flag('json') ? $this->json($plan) : $this->print(self::describe($plan));
        }
        $result = (new DefinitionBuilder(limits: $this->limits))->buildConfigured($image, $output, $config, $assets);
        $summary = [
            'definition' => $result->definitionPath(),
            'id' => $result->definition->id,
            'kind' => $result->definition->kind->value,
            'tiles' => count($result->definition->tiles),
            'files' => count($result->files),
        ];

        return $args->flag('json') ? $this->json($summary) : $this->print(sprintf(
            'Built %s puzzle %s (%d tiles, %d files) in %s',
            $summary['kind'],
            $summary['id'],
            $summary['tiles'],
            $summary['files'],
            $result->directory,
        ));
    }

    private function buildConfiguration(Arguments $args): BuildConfiguration
    {
        $file = $args->value('config');
        $custom = [];
        foreach (['rows', 'cols'] as $key) {
            $value = $args->int($key, 1, 1024);
            if ($value !== null) {
                $custom[$key] = $value;
            }
        }
        foreach ($args->all('param') as $param) {
            if (preg_match('/^([a-zA-Z][a-zA-Z0-9]{0,31})=(.*)$/s', $param, $m) !== 1) {
                throw new UsageException("--param must be KEY=VALUE, got \"{$param}\".");
            }
            if (array_key_exists($m[1], $custom)) {
                throw new UsageException("Parameter {$m[1]} is given more than once.");
            }
            try {
                $custom[$m[1]] = json_decode($m[2], true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $custom[$m[1]] = $m[2];
            }
        }
        if ($file !== null) {
            foreach (['kind', 'preset', 'seed'] as $option) {
                if ($args->value($option) !== null) {
                    throw new UsageException("--{$option} cannot be combined with --config.");
                }
            }
            if ($custom !== []) {
                throw new UsageException('--rows, --cols and --param cannot be combined with --config.');
            }

            return BuildConfiguration::fromJson(self::readLimited($file, BuildConfiguration::MAX_JSON_BYTES, 'build configuration'));
        }
        $kinds = array_map(static fn (GameKind $kind): string => $kind->value, Modes::available());
        $kind = GameKind::from($args->choice('kind', $kinds) ?? GameKind::Jigsaw->value);
        $preset = $args->choice('preset', [...Presets::NAMES, 'custom']) ?? ($custom === [] ? 'medium' : 'custom');

        return new BuildConfiguration($kind, $preset, $custom, $args->int('seed', 0, 4_294_967_295) ?? 0);
    }

    /**
     * Everything generate checks before writing: options, image header,
     * limits, encoder support and the output directory.
     *
     * @return array<string, mixed>
     */
    private function plan(string $image, string $output, BuildConfiguration $config, AssetOptions $assets): array
    {
        if (!in_array($config->kind, Modes::available(), true)) {
            throw new InvalidArgumentException("The {$config->kind->value} mode cannot be built yet.");
        }
        if ($config->kind !== GameKind::Jigsaw && $assets->hasPieceImages()) {
            throw new InvalidArgumentException('Piece resolutions and atlases are only available for jigsaw puzzles.');
        }
        $assets->assertSupported();
        $info = (new ImageLoader($this->limits))->inspect($image);
        $params = $config->preset === 'custom' ? $config->custom : Presets::get($config->kind, $config->preset);
        $rows = is_int($params['rows'] ?? null) ? $params['rows'] : null;
        $cols = is_int($params['cols'] ?? null) ? $params['cols'] : null;
        $pieces = $rows !== null && $cols !== null ? $rows * $cols : (is_int($params['count'] ?? null) ? $params['count'] : null);
        if ($config->kind === GameKind::Jigsaw && $rows !== null && $cols !== null) {
            $this->limits->assertPieceCount($rows, $cols);
        }
        if (is_dir($output)) {
            $entries = scandir($output);
            if ($entries === false || array_diff($entries, ['.', '..']) !== []) {
                throw new InvalidArgumentException("The output directory must be empty: {$output}");
            }
        } elseif (file_exists($output)) {
            throw new InvalidArgumentException("The output path is not a directory: {$output}");
        }
        $width = $config->crop->width ?? $info->outputWidth();
        $height = $config->crop->height ?? $info->outputHeight();

        return [
            'dryRun' => true,
            'source' => ['path' => $image, 'type' => $info->type, 'width' => $info->outputWidth(), 'height' => $info->outputHeight(), 'bytes' => $info->bytes],
            'build' => $config->toArray(),
            'board' => ['width' => $width, 'height' => $height],
            'pieces' => $pieces,
            'estimatedMemoryBytes' => $info->width * $info->height * ResourceLimits::BYTES_PER_PIXEL_ESTIMATE,
            'format' => $assets->format ?? ExportOptions::FORMAT_PNG,
            'resolutions' => $assets->resolutions,
            'atlas' => $assets->atlas,
            'output' => ['path' => $output, 'exists' => is_dir($output)],
        ];
    }

    /**
     * @param array<string, mixed> $plan
     */
    private static function describe(array $plan): string
    {
        $source = is_array($plan['source']) ? $plan['source'] : [];
        $build = is_array($plan['build']) ? $plan['build'] : [];
        $board = is_array($plan['board']) ? $plan['board'] : [];
        $lines = [
            'Dry run: nothing was written.',
            sprintf('Source:  %s (%s, %dx%d, %d bytes)', self::text($source['path'] ?? ''), self::text($source['type'] ?? ''), self::number($source['width'] ?? 0), self::number($source['height'] ?? 0), self::number($source['bytes'] ?? 0)),
            sprintf('Build:   %s, preset %s, seed %d', self::text($build['kind'] ?? ''), self::text($build['preset'] ?? ''), self::number($build['seed'] ?? 0)),
            sprintf('Board:   %dx%d px, %s tiles', self::number($board['width'] ?? 0), self::number($board['height'] ?? 0), is_int($plan['pieces']) ? (string) $plan['pieces'] : 'mode-defined'),
            sprintf('Memory:  about %.1F MiB to decode the source', self::number($plan['estimatedMemoryBytes'] ?? 0) / 1048576),
            sprintf('Images:  %s%s%s', self::text($plan['format']), $plan['resolutions'] === [] ? '' : ', resolutions ' . self::text(json_encode($plan['resolutions'])), $plan['atlas'] === null ? '' : ', atlas ' . self::text($plan['atlas'])),
        ];

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $argv
     */
    private function validate(array $argv): int
    {
        if (in_array('--help', $argv, true)) {
            return $this->print(self::HELP['validate']);
        }
        $args = Arguments::parse($argv, [], ['json']);
        [$path] = $args->expect(1, 1, 'validate <definition.json|puzzle-dir> [--json]');
        $file = is_dir($path) ? rtrim($path, '/') . '/' . DefinitionBuilder::DEFINITION_FILE : $path;
        $definition = GameDefinition::fromJson(self::readLimited($file, GameDefinition::MAX_JSON_BYTES, 'definition'));
        $summary = ['valid' => true, 'id' => $definition->id, 'kind' => $definition->kind->value, 'tiles' => count($definition->tiles)];

        return $args->flag('json') ? $this->json($summary) : $this->print("Valid {$summary['kind']} definition {$summary['id']} ({$summary['tiles']} tiles).");
    }

    /**
     * @param list<string> $argv
     */
    private function inspect(array $argv): int
    {
        if (in_array('--help', $argv, true)) {
            return $this->print(self::HELP['inspect']);
        }
        $args = Arguments::parse($argv, [], ['json']);
        [$path] = $args->expect(1, 1, 'inspect <puzzle-dir> [--json]');
        $puzzle = PuzzleDirectory::open($path);
        $definition = $puzzle->definition;
        $files = [];
        $bytes = 0;
        foreach ($puzzle->referencedFiles() as $relative) {
            $size = (int) filesize($puzzle->file($relative));
            $files[] = ['path' => $relative, 'bytes' => $size];
            $bytes += $size;
        }
        $info = [
            'id' => $definition->id,
            'kind' => $definition->kind->value,
            'preset' => $definition->preset,
            'seed' => $definition->seed,
            'source' => ['width' => $definition->sourceWidth, 'height' => $definition->sourceHeight, 'sha256' => $definition->sourceSha256],
            'board' => ['rows' => $definition->rows, 'cols' => $definition->cols, 'crop' => $definition->crop->toArray()],
            'tiles' => count($definition->tiles),
            'media' => ['image' => $definition->image] + $definition->pieceImages->toArray(),
            'files' => $files,
            'bytes' => $bytes,
        ];
        if ($args->flag('json')) {
            return $this->json($info);
        }
        $crop = $definition->crop;
        $lines = [
            "Definition {$definition->id}",
            "Kind:    {$definition->kind->value} (preset {$definition->preset}, seed {$definition->seed})",
            "Board:   {$definition->rows} x {$definition->cols}, crop {$crop->width}x{$crop->height} at {$crop->x},{$crop->y}",
            'Tiles:   ' . count($definition->tiles),
            "Image:   {$definition->image}",
        ];
        foreach ($definition->pieceImages->resolutions as $resolution) {
            $lines[] = "Set:     {$resolution->name} ({$resolution->width} px wide) in {$resolution->directory}/";
        }
        if ($definition->pieceImages->atlas !== null) {
            $atlas = $definition->pieceImages->atlas;
            $lines[] = "Atlas:   {$atlas->image} ({$atlas->width}x{$atlas->height})";
        }
        $lines[] = sprintf('Files:   %d referenced, %d bytes, all present', count($files), $bytes);

        return $this->print(implode("\n", $lines));
    }

    /**
     * @param list<string> $argv
     */
    private function reconstruct(array $argv): int
    {
        if (in_array('--help', $argv, true)) {
            return $this->print(self::HELP['reconstruct']);
        }
        [$path, $target] = Arguments::parse($argv, [], [])->expect(2, 2, 'reconstruct <puzzle-dir> <output.png>');
        (new Reconstruction($this->limits))->write(PuzzleDirectory::open($path), $target);

        return $this->print("Wrote {$target}");
    }

    /**
     * @param list<string> $argv
     */
    private function svg(array $argv): int
    {
        if (in_array('--help', $argv, true)) {
            return $this->print(self::HELP['svg']);
        }
        $args = Arguments::parse($argv, ['image', 'stroke', 'stroke-width', 'width-mm', 'title'], ['no-pieces', 'no-outlines']);
        [$path, $target] = $args->expect(2, 2, 'svg <puzzle-dir> <output.svg> [options]');
        $image = $args->choice('image', [SvgOptions::IMAGE_LINK, SvgOptions::IMAGE_EMBED, SvgOptions::IMAGE_NONE]) ?? SvgOptions::IMAGE_LINK;
        $options = new SvgOptions(
            image: $image,
            pieces: !$args->flag('no-pieces') && $image !== SvgOptions::IMAGE_NONE,
            outlines: !$args->flag('no-outlines'),
            stroke: $args->value('stroke') ?? '#000000',
            strokeWidth: $args->float('stroke-width') ?? 1.0,
            widthMm: $args->float('width-mm'),
            title: $args->value('title'),
        );
        (new SvgExporter())->write(PuzzleDirectory::open($path), $target, $options);

        return $this->print("Wrote {$target}");
    }

    /**
     * @param list<string> $argv
     */
    private function pdf(array $argv): int
    {
        if (in_array('--help', $argv, true)) {
            return $this->print(self::HELP['pdf']);
        }
        $args = Arguments::parse($argv, ['paper', 'orientation', 'width-mm', 'margin-mm', 'max-dpi', 'title'], ['no-cut-lines', 'no-reference', 'dry-run', 'json']);
        [$path, $target] = $args->expect(2, 2, 'pdf <puzzle-dir> <output.pdf> [options]');
        $options = new PdfOptions(
            paper: $args->choice('paper', array_keys(PdfOptions::PAPER)) ?? 'a4',
            orientation: $args->choice('orientation', ['auto', 'portrait', 'landscape']) ?? 'auto',
            marginMm: $args->float('margin-mm') ?? 10.0,
            widthMm: $args->float('width-mm'),
            cutLines: !$args->flag('no-cut-lines'),
            reference: !$args->flag('no-reference'),
            maxDpi: $args->int('max-dpi', 72, 1200) ?? 300,
            title: $args->value('title'),
        );
        $puzzle = PuzzleDirectory::open($path);
        $exporter = new PdfExporter($this->limits);
        $layout = $exporter->layout($puzzle, $options);
        if (!$args->flag('dry-run')) {
            $exporter->write($puzzle, $target, $options);
        }
        if ($args->flag('json')) {
            return $this->json(['output' => $target, 'dryRun' => $args->flag('dry-run')] + $layout);
        }

        return $this->print(sprintf(
            '%s %s: %d page(s), board %.1F x %.1F mm on %d x %d %s sheet(s) (%.0F x %.0F mm)',
            $args->flag('dry-run') ? 'Would write' : 'Wrote',
            $target,
            $layout['pages'],
            $layout['board'][0],
            $layout['board'][1],
            $layout['sheets'][0],
            $layout['sheets'][1],
            strtoupper($options->paper),
            $layout['paper'][0],
            $layout['paper'][1],
        ));
    }

    /**
     * @param list<string> $argv
     */
    private function bundle(array $argv): int
    {
        if (in_array('--help', $argv, true)) {
            return $this->print(self::HELP['bundle']);
        }
        $args = Arguments::parse($argv, ['folder', 'title'], ['dry-run', 'json']);
        [$path, $target] = $args->expect(2, 2, 'bundle <puzzle-dir> <output.zip> [options]');
        $puzzle = PuzzleDirectory::open($path);
        $exporter = new BundleExporter(limits: $this->limits);
        $folder = $args->value('folder') ?? 'image-puzzle';
        if ($args->flag('dry-run')) {
            $entries = $exporter->entries($puzzle, $args->value('title'));
            $list = [];
            foreach ($entries as $name => $bytes) {
                $list[] = ['path' => "{$folder}/{$name}", 'bytes' => strlen($bytes)];
            }
            if ($args->flag('json')) {
                return $this->json(['output' => $target, 'dryRun' => true, 'zipAvailable' => BundleExporter::supported(), 'entries' => $list]);
            }
            $lines = ['Dry run: nothing was written. Entries:'];
            foreach ($list as $entry) {
                $lines[] = sprintf('  %9d  %s', $entry['bytes'], $entry['path']);
            }
            if (!BundleExporter::supported()) {
                $lines[] = 'Note: ext-zip is not loaded; writing the archive would fail.';
            }

            return $this->print(implode("\n", $lines));
        }
        $names = $exporter->write($puzzle, $target, $folder, $args->value('title'));

        return $args->flag('json')
            ? $this->json(['output' => $target, 'entries' => $names])
            : $this->print(sprintf('Wrote %s (%d entries). Extract it and open %s/index.html.', $target, count($names), $folder));
    }

    /**
     * @param list<string> $argv
     */
    private function batch(array $argv): int
    {
        if (in_array('--help', $argv, true)) {
            return $this->print(self::HELP['batch']);
        }
        $args = Arguments::parse($argv, ['concurrency', 'checkpoint', 'cache', 'cache-max-mb', 'cancel-file'], ['fail-fast', 'json']);
        [$file] = $args->expect(1, 1, 'batch <batch.json> [options]');
        $manifest = BatchManifest::fromJson(self::readLimited($file, BatchManifest::MAX_JSON_BYTES, 'batch'), dirname($file));
        $options = new BatchOptions($args->flag('fail-fast'), $args->int('concurrency', 1, BatchOptions::MAX_CONCURRENCY) ?? 1, $args->value('checkpoint'));
        $cacheDir = $args->value('cache');
        $cache = $cacheDir === null ? null : new AssetCache($cacheDir, ($args->int('cache-max-mb', 1, 1 << 20) ?? 1024) * 1024 * 1024);
        $cancelFile = $args->value('cancel-file');
        if ($cancelFile !== null && file_exists($cancelFile)) {
            throw new InvalidArgumentException("The cancel file {$cancelFile} already exists; remove it to start the batch.");
        }
        $token = new CancellationToken($cancelFile);
        $err = $this->err;
        $listener = static function (BatchEvent $event) use ($err): void {
            if ($event->type === 'item-end') {
                fwrite($err, sprintf("[%d/%d] %s %s%s\n", $event->completed, $event->total, (string) $event->item, (string) $event->status, $event->error === null ? '' : ': ' . $event->error));
            }
        };
        $signals = PHP_SAPI === 'cli' && function_exists('pcntl_signal') && function_exists('pcntl_async_signals') && defined('SIGINT');
        if ($signals) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, static function () use ($token): void {
                $token->cancel();
            });
        }
        try {
            $result = (new BatchRunner(new DefinitionBuilder(limits: $this->limits), $cache))->run($manifest->items, $options, $listener, $token);
        } finally {
            if ($signals) {
                pcntl_signal(SIGINT, SIG_DFL);
            }
        }
        $counts = $result->counts();
        if ($args->flag('json')) {
            $this->json($result->toArray());
        } else {
            $this->print(sprintf(
                'Batch %s: %d built, %d resumed, %d failed, %d cancelled, %d pending.',
                $result->status,
                $counts[ItemResult::DONE],
                $counts[ItemResult::RESUMED],
                $counts[ItemResult::FAILED],
                $counts[ItemResult::CANCELED],
                $counts[ItemResult::PENDING],
            ));
        }

        return $result->status === BatchResult::COMPLETE ? self::EXIT_OK : self::EXIT_FAILURE;
    }

    /**
     * @param list<string> $argv
     */
    private function capabilities(array $argv): int
    {
        $args = Arguments::parse($argv, [], ['json']);
        $args->expect(0, 0, 'capabilities [--json]');
        $gd = extension_loaded('gd');
        $info = [
            'php' => PHP_VERSION,
            'modes' => array_map(static fn (GameKind $kind): string => $kind->value, Modes::available()),
            'gd' => $gd,
            'decode' => $gd ? ImageLoader::decoders() : [],
            'encode' => $gd ? ImageEncoder::capabilities() : [],
            'zip' => BundleExporter::supported(),
            'parallel' => function_exists('pcntl_fork') && function_exists('posix_getpid'),
            'exif' => function_exists('exif_read_data'),
            'flate' => function_exists('gzcompress'),
        ];
        if ($args->flag('json')) {
            return $this->json($info);
        }
        $yes = static fn (bool $value): string => $value ? 'yes' : 'no';
        $list = static function (array $flags): string {
            $on = array_keys(array_filter($flags, static fn (mixed $on): bool => $on === true));

            return $on === [] ? 'none' : implode(', ', $on);
        };

        return $this->print(implode("\n", [
            'PHP:          ' . PHP_VERSION,
            'Modes:        ' . implode(', ', $info['modes']),
            'GD:           ' . $yes($gd),
            'Decode:       ' . $list($info['decode']),
            'Encode:       ' . $list($info['encode']),
            'ZIP bundles:  ' . $yes($info['zip']) . ($info['zip'] ? '' : ' (install ext-zip)'),
            'Parallel:     ' . $yes($info['parallel']) . ' (ext-pcntl, ext-posix)',
            'EXIF:         ' . $yes($info['exif']),
            'Flate:        ' . $yes($info['flate']) . ' (compressed PDF streams)',
        ]));
    }

    /**
     * @return array<string, int>|list<string>
     */
    private static function resolutions(?string $value): array
    {
        if ($value === null) {
            return [];
        }
        $named = [];
        $plain = [];
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if (preg_match('/^([a-z][a-z0-9-]{0,15}):([0-9]{1,5})$/', $item, $m) === 1) {
                $named[$m[1]] = (int) $m[2];
            } elseif ($item !== '') {
                $plain[] = $item;
            }
        }
        if ($named !== [] && $plain !== []) {
            foreach ($plain as $name) {
                $width = AssetOptions::RESOLUTIONS[$name] ?? throw new InvalidArgumentException("Unknown resolution \"{$name}\"; use " . implode(', ', array_keys(AssetOptions::RESOLUTIONS)) . ' or name:width.');
                $named[$name] = $width;
            }

            return $named;
        }

        return $named !== [] ? $named : $plain;
    }

    private static function readLimited(string $file, int $maxBytes, string $what): string
    {
        if (!is_file($file)) {
            throw new InvalidArgumentException("No {$what} file at {$file}.");
        }
        $size = (int) filesize($file);
        if ($size > $maxBytes) {
            throw new ResourceLimitExceededException("The {$what} file is {$size} bytes; the limit is {$maxBytes}.");
        }
        $json = ErrorCapture::run(static fn (): string|false => file_get_contents($file));
        if ($json === false) {
            throw new InvalidArgumentException("Cannot read {$file}.");
        }

        return $json;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function number(mixed $value): int|float
    {
        return is_int($value) || is_float($value) ? $value : 0;
    }

    private function print(string $text): int
    {
        fwrite($this->out, $text . "\n");

        return self::EXIT_OK;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): int
    {
        return $this->print(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function fail(string $message, int $code): int
    {
        fwrite($this->err, 'image-puzzle: ' . $message . "\n");

        return $code;
    }
}
