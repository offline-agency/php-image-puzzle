<?php

declare(strict_types=1);

namespace ImagePuzzle\Tools;

use ImagePuzzle\Config\PuzzleConfig;
use ImagePuzzle\Exception\IllegalActionException;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Game\BuildConfiguration;
use ImagePuzzle\Game\Doc;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameFactory;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\Modes;
use ImagePuzzle\Game\PieceAtlas;
use ImagePuzzle\Game\PieceImages;
use ImagePuzzle\Game\PieceResolution;
use ImagePuzzle\Game\Presets;
use ImagePuzzle\Game\Prng;
use ImagePuzzle\Game\SourceImage;
use ImagePuzzle\Geometry\Bounds;
use ImagePuzzle\GeometryOptions;
use ImagePuzzle\PuzzleGenerator;

/**
 * Golden fixtures that the TypeScript engine must reproduce exactly
 * (conformance/*.json). They are generated from the PHP rules; a test fails
 * when the committed files drift from what PHP produces.
 */
final class ConformanceFixtures
{
    /**
     * @return array<string, array<string, mixed>> file name => document
     */
    public static function build(): array
    {
        $files = [
            'prng.json' => self::prng(),
            'presets.json' => Presets::export(),
            'build-config.json' => self::buildConfig(),
        ];
        $factory = new GameFactory();
        $source = new SourceImage(640, 480, hash('sha256', 'conformance-source'));
        $definitions = [
            'sliding' => $factory->create(GameKind::Sliding, $source, 'source.png', 'easy', [], 11),
            'sliding-2x3' => $factory->create(GameKind::Sliding, $source, 'source.png', 'custom', ['rows' => 2, 'cols' => 3, 'scrambleMoves' => 25], 5),
            'swap' => $factory->create(GameKind::Swap, $source, 'source.png', 'easy', [], 12),
            'rotation' => $factory->create(GameKind::Rotation, $source, 'source.png', 'medium', [], 13),
            'rotation-half' => $factory->create(GameKind::Rotation, $source, 'source.png', 'easy', [], 14),
            'strips' => $factory->create(GameKind::Strips, $source, 'source.png', 'easy', [], 15),
            'strips-vertical' => $factory->create(GameKind::Strips, $source, 'source.png', 'custom', ['count' => 5, 'axis' => 'vertical'], 16),
            'jigsaw' => self::jigsaw($factory, 'rect', 2, 3),
            'jigsaw-hex' => self::jigsaw($factory, 'hex', 2, 2),
            'nonogram' => self::nonogram($factory, ['..##..', '.####.', '######', '##..##', '#....#', '######']),
            // Two diagonals: the clues also fit the mirrored picture.
            'nonogram-ambiguous' => self::nonogram($factory, ['#...', '.#..', '..#.', '...#']),
        ];
        foreach ($definitions as $name => $definition) {
            $files["mode-{$name}.json"] = self::mode($name, $definition);
        }
        $files['media.json'] = self::media($definitions['jigsaw'], $definitions['swap']);

        return $files;
    }

    /**
     * Definitions with alternative piece images (definition.media) and the
     * PHP verdict: the TypeScript parser accepts and rejects the same ones.
     *
     * @return array<string, mixed>
     */
    private static function media(GameDefinition $jigsaw, GameDefinition $swap): array
    {
        $frames = [];
        foreach ($jigsaw->tiles as $i => $tile) {
            $frames[$tile->id] = new Bounds(2 + 60 * $i, 2, 50, 60);
        }
        $atlas = new PieceAtlas('atlas.png', 2 + 60 * count($frames), 64, $frames);
        $resolutions = [new PieceResolution('thumbnail', 30, 'pieces-thumbnail'), new PieceResolution('mobile', 60, 'pieces-mobile')];
        $valid = [
            'resolutions' => $jigsaw->withPieceImages(new PieceImages($resolutions)),
            'atlas' => $jigsaw->withPieceImages(new PieceImages([], $atlas)),
            'both' => $jigsaw->withPieceImages(new PieceImages($resolutions, $atlas)),
        ];
        $cases = [];
        foreach ($valid as $name => $definition) {
            $cases[] = ['name' => $name, 'document' => $definition->toArray()];
        }
        $both = $valid['both']->toArray();
        $media = Doc::object($both['media'], 'media');
        $resolution = ['name' => 'tiny', 'width' => 10, 'directory' => 'pieces-tiny'];
        $mutations = [
            'empty resolutions' => [...$media, 'resolutions' => []],
            'unknown media field' => [...$media, 'video' => 'clip.mp4'],
            'resolution as wide as the source' => [...$media, 'resolutions' => [[...$resolution, 'width' => 120]]],
            'resolution in the tile image directory' => [...$media, 'resolutions' => [[...$resolution, 'directory' => 'pieces']]],
            'repeated resolution name' => [...$media, 'resolutions' => [$resolution, [...$resolution, 'directory' => 'other']]],
            'invalid resolution name' => [...$media, 'resolutions' => [[...$resolution, 'name' => 'Tiny']]],
            'unsafe resolution directory' => [...$media, 'resolutions' => [[...$resolution, 'directory' => '../up']]],
            'zero resolution width' => [...$media, 'resolutions' => [[...$resolution, 'width' => 0]]],
            'extra resolution field' => [...$media, 'resolutions' => [[...$resolution, 'scale' => 0.5]]],
            'too many resolutions' => [...$media, 'resolutions' => array_map(static fn (int $i): array => ['name' => "r{$i}", 'width' => 10 + $i, 'directory' => "r{$i}"], range(1, 5))],
            'unsafe atlas image' => [...$media, 'atlas' => [...$atlas->toArray(), 'image' => '/etc/atlas.png']],
            'atlas too large' => [...$media, 'atlas' => [...$atlas->toArray(), 'width' => 20000]],
            'frame outside the atlas' => [...$media, 'atlas' => [...$atlas->toArray(), 'height' => 40]],
            'missing frame' => [...$media, 'atlas' => [...$atlas->toArray(), 'frames' => array_slice($atlas->toArray()['frames'], 1)]],
            'frames out of tile order' => [...$media, 'atlas' => [...$atlas->toArray(), 'frames' => array_reverse($atlas->toArray()['frames'])]],
            'invalid frame id' => [...$media, 'atlas' => [...$atlas->toArray(), 'frames' => ['Bad' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]]]],
        ];
        foreach ($mutations as $name => $value) {
            $cases[] = ['name' => $name, 'document' => [...$both, 'media' => $value]];
        }
        $cases[] = ['name' => 'atlas on a grid mode', 'document' => [...$swap->toArray(), 'media' => ['image' => $swap->image, 'atlas' => ['image' => 'atlas.png', 'width' => 10, 'height' => 10, 'frames' => ['a' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]]]]]];
        foreach ($cases as &$case) {
            // Validated as a JSON reader sees the document.
            $decoded = json_decode(json_encode($case['document'], JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
            try {
                $case['valid'] = true;
                $case['id'] = GameDefinition::fromArray($decoded)->id;
            } catch (InvalidDefinitionException $e) {
                $case['valid'] = false;
                $case['error'] = $e->getMessage();
            }
        }
        unset($case);

        return ['cases' => $cases];
    }

    /**
     * Accepted and rejected build configurations with the PHP verdict; the
     * TypeScript configurator validates the same documents identically.
     *
     * @return array<string, mixed>
     */
    private static function buildConfig(): array
    {
        $base = ['format' => BuildConfiguration::FORMAT, 'version' => BuildConfiguration::VERSION, 'kind' => 'swap', 'preset' => 'easy', 'seed' => 0];
        $documents = [
            $base,
            ['format' => BuildConfiguration::FORMAT, 'version' => 1, 'kind' => 'jigsaw', 'preset' => 'custom', 'custom' => ['rows' => 3, 'cols' => 5, 'topology' => 'hex', 'edge' => 'jigsaw', 'snap' => 12], 'seed' => 2147483647, 'crop' => ['x' => 0, 'y' => 4, 'width' => 300, 'height' => 200]],
            ['format' => BuildConfiguration::FORMAT, 'version' => 1, 'kind' => 'strips', 'preset' => 'custom', 'custom' => ['count' => 6, 'axis' => 'vertical'], 'seed' => 9],
            ['$schema' => 'https://example.test/x.json', ...$base],
            'not an object',
            [],
            [...$base, 'format' => 'image-puzzle/game-state'],
            [...$base, 'version' => 2],
            [...$base, 'version' => '1'],
            [...$base, 'extra' => true],
            [...$base, 'kind' => 'memory'],
            [...$base, 'kind' => 7],
            [...$base, 'preset' => 'expert'],
            [...$base, 'custom' => ['rows' => 2]],
            [...$base, 'preset' => 'custom'],
            [...$base, 'preset' => 'custom', 'custom' => ['count' => 3]],
            [...$base, 'preset' => 'custom', 'custom' => [1, 2]],
            [...$base, 'seed' => -1],
            [...$base, 'seed' => 4294967296],
            [...$base, 'seed' => 1.5],
            [...$base, 'seed' => 4294967295],
            [...$base, 'kind' => 'jigsaw', 'seed' => 2147483648],
            [...$base, 'crop' => ['x' => 0, 'y' => 0, 'width' => 10]],
            [...$base, 'crop' => ['x' => -1, 'y' => 0, 'width' => 10, 'height' => 10]],
            [...$base, 'crop' => ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 10]],
            [...$base, 'crop' => ['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10, 'unit' => 'px']],
        ];
        $cases = [];
        foreach ($documents as $document) {
            try {
                $cases[] = ['document' => $document, 'valid' => true, 'normalized' => BuildConfiguration::fromArray($document)->toArray()];
            } catch (InvalidDefinitionException $e) {
                $cases[] = ['document' => $document, 'valid' => false, 'error' => $e->getMessage()];
            }
        }
        $customKeys = [];
        foreach (Modes::available() as $kind) {
            $customKeys[$kind->value] = GameFactory::customKeys($kind);
        }

        return ['format' => BuildConfiguration::FORMAT, 'version' => BuildConfiguration::VERSION, 'maxSeed' => Prng::MAX_SEED, 'maxJigsawSeed' => GeometryOptions::MAX_SEED, 'customKeys' => $customKeys, 'cases' => $cases];
    }

    /**
     * @return array<string, mixed>
     */
    private static function prng(): array
    {
        $vectors = [];
        foreach ([[0, ''], [1, 'a'], [42, 'test'], [4294967295, 'image-puzzle/scramble/sliding'], [123456789, 'Ünïcode ✓']] as [$seed, $stream]) {
            $rng = new Prng($seed, $stream);
            $uint32 = [];
            for ($i = 0; $i < 8; $i++) {
                $uint32[] = $rng->nextUint32();
            }
            $ints = [];
            foreach ([1, 2, 3, 10, 1000, Prng::MAX_RANGE] as $n) {
                $ints[] = ['n' => $n, 'value' => $rng->int($n)];
            }
            $vectors[] = [
                'seed' => $seed,
                'stream' => $stream,
                'fnv1a' => Prng::fnv1a($stream),
                'uint32' => $uint32,
                'int' => $ints,
                'shuffle' => $rng->shuffle(range(0, 9)),
            ];
        }

        return ['algorithm' => 'fnv1a32-xor-seed + mulberry32', 'vectors' => $vectors];
    }

    private static function jigsaw(GameFactory $factory, string $topology, int $rows, int $cols): GameDefinition
    {
        $dir = sys_get_temp_dir() . '/image-puzzle-conformance-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        try {
            $image = imagecreatetruecolor(120, 90);
            imagefilledrectangle($image, 0, 0, 119, 89, (int) imagecolorallocate($image, 200, 100, 50));
            imagepng($image, $dir . '/source.png');
            $config = PuzzleConfig::fromArray([
                'configVersion' => 2,
                'rows' => $rows,
                'cols' => $cols,
                'edge' => ['type' => 'jigsaw'],
                'geometry' => ['seed' => 9, 'samples' => 1, 'topology' => $topology],
            ]);
            $result = PuzzleGenerator::generateAndExport($dir . '/source.png', $dir . '/out', $rows, $cols, $config->edgeStyle(), $config->exportOptions(), geometry: $config->geometryOptions());
            $manifest = json_decode((string) file_get_contents($result->getManifestPath()), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($manifest)) {
                throw new \RuntimeException('Manifest is not an object.');
            }
            $manifestArray = [];
            foreach ($manifest as $key => $value) {
                $manifestArray[(string) $key] = $value;
            }

            return $factory->jigsawFromManifest($manifestArray, 'source.png', 'easy', 21, null, 'pieces/');
        } finally {
            self::remove($dir);
        }
    }

    /**
     * A nonogram of a black-on-white picture, 8 px per cell.
     *
     * @param list<string> $pattern "#" filled
     */
    private static function nonogram(GameFactory $factory, array $pattern): GameDefinition
    {
        $rows = count($pattern);
        $cols = strlen($pattern[0]);
        $image = imagecreatetruecolor(max(1, $cols * 8), max(1, $rows * 8));
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $black = (int) imagecolorallocate($image, 0, 0, 0);
        foreach ($pattern as $r => $line) {
            for ($c = 0; $c < $cols; $c++) {
                imagefilledrectangle($image, $c * 8, $r * 8, $c * 8 + 7, $r * 8 + 7, $line[$c] === '#' ? $black : $white);
            }
        }

        return $factory->nonogram(new SourceImage($cols * 8, $rows * 8, hash('sha256', implode('/', $pattern))), $image, 'source.png', 'custom', ['rows' => $rows, 'cols' => $cols], 17);
    }

    /**
     * @return array<string, mixed>
     */
    private static function mode(string $name, GameDefinition $definition): array
    {
        $rules = $definition->rules();
        $scrambles = [];
        foreach ([0, 1, 2, 99, 4294967295] as $seed) {
            $scrambles[] = ['seed' => $seed, 'board' => $rules->scramble($definition, $seed)];
        }

        // Play hints (or the first legal action) from the initial board.
        $board = $definition->initial;
        $steps = [];
        for ($i = 0; $i < 400 && !$rules->isComplete($definition, $board); $i++) {
            $action = $rules->hint($definition, $board) ?? $rules->legalActions($definition, $board)[0];
            $steps[] = self::step($definition, $board, $action);
            $board = $rules->apply($definition, $board, $action);
            if ($definition->kind === GameKind::Sliding && $i >= 12) {
                break;
            }
        }
        $cases = [['name' => 'play from initial', 'board' => $definition->initial, 'steps' => $steps]];
        $cases[] = ['name' => 'last move completes', 'board' => self::nearlySolved($definition), 'steps' => self::finish($definition, self::nearlySolved($definition))];
        $cases[] = ['name' => 'rejected actions', 'board' => $definition->initial, 'steps' => array_map(
            static fn (array $action): array => self::step($definition, $definition->initial, $action),
            self::badActions($definition),
        )];

        return [
            'name' => $name,
            'definition' => $definition->toArray(),
            'solved' => $rules->solvedBoard($definition),
            'scrambles' => $scrambles,
            'legalActions' => $rules->legalActions($definition, $definition->initial),
            'cases' => $cases,
            'imports' => self::imports($definition),
        ];
    }

    /**
     * @param array<string, mixed> $board
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    private static function step(GameDefinition $definition, array $board, array $action): array
    {
        $rules = $definition->rules();
        try {
            $next = $rules->apply($definition, $board, $action);
        } catch (IllegalActionException) {
            return ['action' => $action, 'result' => 'illegal'];
        } catch (InvalidDefinitionException) {
            return ['action' => $action, 'result' => 'invalid'];
        }

        return [
            'action' => $action,
            'result' => 'ok',
            'board' => $next,
            'complete' => $rules->isComplete($definition, $next),
            'progress' => $rules->progress($definition, $next),
            'hint' => $rules->hint($definition, $next),
        ];
    }

    /**
     * A board one legal action away from solved.
     *
     * @return array<string, mixed>
     */
    private static function nearlySolved(GameDefinition $definition): array
    {
        $rules = $definition->rules();
        $solved = $rules->solvedBoard($definition);

        return match ($definition->kind) {
            GameKind::Jigsaw => (static function () use ($definition, $solved): array {
                $initial = $definition->initial;
                $pieces = $solved['pieces'];
                if (!is_array($pieces) || !is_array($initial['pieces']) || !isset($initial['pieces'][0])) {
                    throw new \LogicException('Malformed jigsaw board.');
                }
                $pieces[0] = $initial['pieces'][0];

                return ['pieces' => $pieces];
            })(),
            default => $rules->apply($definition, $solved, $rules->legalActions($definition, $solved)[0]),
        };
    }

    /**
     * @param array<string, mixed> $board
     * @return list<array<string, mixed>>
     */
    private static function finish(GameDefinition $definition, array $board): array
    {
        $rules = $definition->rules();
        foreach ($rules->legalActions($definition, $board) as $action) {
            if ($rules->isComplete($definition, $rules->apply($definition, $board, $action))) {
                return [self::step($definition, $board, $action)];
            }
        }

        throw new \LogicException("No completing action for {$definition->kind->value}.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function badActions(GameDefinition $definition): array
    {
        $first = ($definition->tiles[0] ?? null)->id ?? 'zz';
        $second = ($definition->tiles[1] ?? null)->id ?? $first;
        $common = [
            ['type' => 'teleport', 'tile' => $first],
            ['type' => $definition->rules()->actionTypes()[0]],
        ];

        return [...$common, ...match ($definition->kind) {
            GameKind::Sliding => [
                ['type' => 'slide', 'tile' => 'zz'],
                ['type' => 'slide', 'tile' => 'BAD ID'],
                ['type' => 'slide', 'tile' => self::farTile($definition)],
            ],
            GameKind::Swap => [
                ['type' => 'swap', 'a' => $first, 'b' => $first],
                ['type' => 'swap', 'a' => $first, 'b' => 'zz'],
                ['type' => 'swap', 'a' => $first, 'b' => $second, 'extra' => 1],
            ],
            GameKind::Rotation => [
                ['type' => 'rotate', 'tile' => $first, 'direction' => 'up'],
                ['type' => 'rotate', 'tile' => 'zz', 'direction' => 'cw'],
            ],
            GameKind::Strips => [
                ['type' => 'move', 'strip' => $first, 'to' => 999],
                ['type' => 'move', 'strip' => $first, 'to' => -1],
                ['type' => 'move', 'strip' => 'zz', 'to' => 0],
                ['type' => 'move', 'strip' => self::stripAt($definition, 0), 'to' => 0],
            ],
            GameKind::Jigsaw => [
                ['type' => 'place', 'piece' => $first, 'x' => -500, 'y' => 0],
                ['type' => 'place', 'piece' => 'zz', 'x' => 0, 'y' => 0],
                ['type' => 'place', 'piece' => $first, 'x' => 1.5, 'y' => 0],
            ],
            GameKind::Nonogram => [
                ['type' => 'mark', 'cell' => 0, 'state' => 'unknown'],
                ['type' => 'mark', 'cell' => $definition->rows * $definition->cols, 'state' => 'filled'],
                ['type' => 'mark', 'cell' => -1, 'state' => 'filled'],
                ['type' => 'mark', 'cell' => '0', 'state' => 'filled'],
                ['type' => 'mark', 'cell' => 0, 'state' => 'grey'],
                ['type' => 'mark', 'cell' => 0, 'state' => 'filled', 'extra' => 1],
            ],
            default => [],
        }];
    }

    private static function farTile(GameDefinition $definition): string
    {
        $cells = $definition->initial['cells'];
        if (!is_array($cells)) {
            throw new \LogicException('Malformed sliding board.');
        }
        $gap = (int) array_search(null, $cells, true);
        $cols = $definition->cols;
        foreach ($cells as $i => $id) {
            $distance = abs(intdiv($i, $cols) - intdiv($gap, $cols)) + abs($i % $cols - $gap % $cols);
            if (is_string($id) && $distance > 1) {
                return $id;
            }
        }

        throw new \LogicException('No far tile.');
    }

    private static function stripAt(GameDefinition $definition, int $index): string
    {
        $order = $definition->initial['order'];
        if (!is_array($order) || !is_string($order[$index] ?? null)) {
            throw new \LogicException('Malformed strips board.');
        }

        return $order[$index];
    }

    /**
     * Imported boards and whether they must be accepted.
     *
     * @return list<array{name: string, board: mixed, valid: bool}>
     */
    private static function imports(GameDefinition $definition): array
    {
        $rules = $definition->rules();
        $solved = $rules->solvedBoard($definition);
        $initial = $definition->initial;
        $cases = [
            ['name' => 'initial', 'board' => $initial],
            ['name' => 'solved', 'board' => $solved],
            ['name' => 'not an object', 'board' => [1, 2]],
            ['name' => 'unknown field', 'board' => $initial + ['cheat' => true]],
        ];
        $first = ($definition->tiles[0] ?? null)->id ?? 'zz';
        $second = ($definition->tiles[1] ?? null)->id ?? $first;
        $cells = $definition->rows * $definition->cols;
        $cases = [...$cases, ...match ($definition->kind) {
            GameKind::Sliding => [
                ['name' => 'duplicate id', 'board' => ['cells' => self::replace($solved['cells'], $second, $first)]],
                ['name' => 'two gaps', 'board' => ['cells' => self::replace($solved['cells'], $first, null)]],
                ['name' => 'unreachable (two tiles exchanged)', 'board' => ['cells' => self::exchange($solved['cells'], 0, 1)]],
                ['name' => 'missing cell', 'board' => ['cells' => self::drop($solved['cells'])]],
            ],
            GameKind::Swap => [
                ['name' => 'duplicate id', 'board' => ['cells' => self::replace($solved['cells'], $second, $first)]],
                ['name' => 'unknown id', 'board' => ['cells' => self::replace($solved['cells'], $first, 'zz')]],
                ['name' => 'missing cell', 'board' => ['cells' => self::drop($solved['cells'])]],
            ],
            GameKind::Rotation => [
                ['name' => 'invalid orientation', 'board' => ['orientations' => self::replaceAt($solved['orientations'], 0, 45)]],
                ['name' => 'string orientation', 'board' => ['orientations' => self::replaceAt($solved['orientations'], 0, '90')]],
                ['name' => 'missing tile', 'board' => ['orientations' => self::drop($solved['orientations'])]],
            ],
            GameKind::Strips => [
                ['name' => 'duplicate id', 'board' => ['order' => self::replace($solved['order'], $second, $first)]],
                ['name' => 'missing strip', 'board' => ['order' => self::drop($solved['order'])]],
            ],
            GameKind::Jigsaw => [
                ['name' => 'placed away from home', 'board' => ['pieces' => self::replaceAt($solved['pieces'], 0, ['x' => 1, 'y' => 1, 'placed' => true])]],
                ['name' => 'inside snap zone but loose', 'board' => ['pieces' => self::replaceAt($solved['pieces'], 0, self::loose($solved['pieces']))]],
                ['name' => 'outside area', 'board' => ['pieces' => self::replaceAt($solved['pieces'], 0, ['x' => -9999, 'y' => 0, 'placed' => false])]],
            ],
            GameKind::Nonogram => [
                ['name' => 'partly marked', 'board' => ['cells' => '1x' . str_repeat('.', $cells - 2)]],
                ['name' => 'wrong but well formed', 'board' => ['cells' => str_repeat('1', $cells)]],
                ['name' => 'too short', 'board' => ['cells' => str_repeat('.', $cells - 1)]],
                ['name' => 'unknown symbol', 'board' => ['cells' => '0' . str_repeat('.', $cells - 1)]],
                ['name' => 'list of cells', 'board' => ['cells' => array_fill(0, $cells, '.')]],
            ],
            default => [],
        }];

        $out = [];
        foreach ($cases as $case) {
            try {
                $rules->validateBoard($definition, $case['board']);
                $valid = true;
            } catch (InvalidDefinitionException) {
                $valid = false;
            }
            $out[] = ['name' => $case['name'], 'board' => $case['board'], 'valid' => $valid];
        }

        return $out;
    }

    /**
     * @return list<mixed>
     */
    private static function replace(mixed $list, mixed $search, mixed $replacement): array
    {
        $list = self::asList($list);
        $index = array_search($search, $list, true);

        return self::replaceAt($list, is_int($index) ? $index : 0, $replacement);
    }

    /**
     * @return list<mixed>
     */
    private static function replaceAt(mixed $list, int $index, mixed $replacement): array
    {
        $list = self::asList($list);
        $list[$index] = $replacement;

        return array_values($list);
    }

    /**
     * @return list<mixed>
     */
    private static function exchange(mixed $list, int $i, int $j): array
    {
        $list = self::asList($list);
        [$list[$i], $list[$j]] = [$list[$j], $list[$i]];

        return array_values($list);
    }

    /**
     * @return list<mixed>
     */
    private static function drop(mixed $list): array
    {
        $list = self::asList($list);
        array_pop($list);

        return $list;
    }

    /**
     * @return array{x: int, y: int, placed: false}
     */
    private static function loose(mixed $pieces): array
    {
        $first = self::asList($pieces)[0] ?? null;
        if (!is_array($first) || !is_int($first['x'] ?? null) || !is_int($first['y'] ?? null)) {
            throw new \LogicException('Malformed jigsaw piece.');
        }

        return ['x' => $first['x'] + 1, 'y' => $first['y'], 'placed' => false];
    }

    /**
     * @return list<mixed>
     */
    private static function asList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \LogicException('Expected a list.');
        }

        return $value;
    }

    private static function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
