<?php

declare(strict_types=1);

namespace ImagePuzzle\Game;

use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Game\Rules\ModeRules;
use ImagePuzzle\Geometry\Bounds;

/**
 * Immutable, versioned game definition (docs/schemas/game/v1/definition.schema.json).
 *
 * It references the source image and the geometry produced by PHP, fixes the
 * mode rules and carries the initial scramble. Player state is separate
 * (GameState) and UI options such as colours or sounds never belong here.
 *
 * The ID is "gd1-" plus the SHA-256 of the canonical JSON of every other
 * field, so it is bound to the source digest, configuration, preset, seed,
 * schema version and generator algorithm version.
 */
final readonly class GameDefinition
{
    public const FORMAT = 'image-puzzle/game-definition';
    public const VERSION = 1;
    /** Bump when generation changes for identical inputs (tiles, crop or scramble). */
    public const GENERATOR = 1;
    public const MAX_JSON_BYTES = 4 * 1024 * 1024;
    public const MAX_TILES = 4096;
    public const SCHEMA_ID = 'https://raw.githubusercontent.com/offline-agency/php-image-puzzle/HEAD/docs/schemas/game/v1/definition.schema.json';

    private const MEDIA_PATTERN = '`^(?:https://[A-Za-z0-9._~:/?#\[\]@!$&\'()*+,;=%-]+|[A-Za-z0-9_~-][A-Za-z0-9._~/-]*)$`';

    /**
     * @param list<Tile> $tiles
     * @param array<string, mixed> $rules
     * @param array{preview: bool, numbers: bool, highlightCorrect: bool} $assistance
     * @param array<string, mixed> $initial
     */
    private function __construct(
        public string $id,
        public GameKind $kind,
        public int $seed,
        public string $preset,
        public int $presetVersion,
        public int $sourceWidth,
        public int $sourceHeight,
        public ?string $sourceSha256,
        public string $image,
        public int $rows,
        public int $cols,
        public Bounds $crop,
        public array $tiles,
        public array $rules,
        public array $assistance,
        public array $initial,
    ) {
    }

    /**
     * Assembles a definition, validates it with the mode rules and computes
     * the deterministic scramble for $seed.
     *
     * @param list<Tile> $tiles
     * @param array<string, mixed> $rules
     * @param array{preview: bool, numbers: bool, highlightCorrect: bool} $assistance
     */
    public static function create(
        GameKind $kind,
        int $seed,
        string $preset,
        int $presetVersion,
        int $sourceWidth,
        int $sourceHeight,
        ?string $sourceSha256,
        string $image,
        int $rows,
        int $cols,
        Bounds $crop,
        array $tiles,
        array $rules,
        array $assistance,
    ): self {
        $draft = new self('', $kind, $seed, $preset, $presetVersion, $sourceWidth, $sourceHeight, $sourceSha256, $image, $rows, $cols, $crop, $tiles, $rules, $assistance, []);
        $draft->validateCommon();
        $mode = Modes::rules($kind);
        $mode->validateDefinition($draft);
        $initial = $mode->scramble($draft, $seed);
        $complete = new self('', $kind, $seed, $preset, $presetVersion, $sourceWidth, $sourceHeight, $sourceSha256, $image, $rows, $cols, $crop, $tiles, $rules, $assistance, $initial);

        return $complete->withId();
    }

    public static function fromJson(string $json): self
    {
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new InvalidDefinitionException('Game definition exceeds ' . self::MAX_JSON_BYTES . ' bytes.');
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidDefinitionException('Game definition is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        return self::fromArray($data);
    }

    /**
     * Validates an external definition document. The ID must match the
     * content; a stale format version is rejected rather than guessed.
     */
    public static function fromArray(mixed $data): self
    {
        $doc = Doc::object($data, 'definition');
        Doc::keys($doc, ['$schema', 'format', 'version', 'id', 'kind', 'generator', 'seed', 'preset', 'source', 'media', 'board', 'tiles', 'rules', 'capabilities', 'actions', 'assistance', 'initial'], 'definition');
        if (($doc['format'] ?? null) !== self::FORMAT) {
            throw new InvalidDefinitionException('definition.format must be "' . self::FORMAT . '".');
        }
        $version = Doc::int($doc, 'version', 'definition', 1);
        if ($version !== self::VERSION) {
            throw new InvalidDefinitionException("Unsupported game definition version {$version}; this library reads version " . self::VERSION . '.');
        }
        $kind = GameKind::tryFrom(Doc::string($doc, 'kind', 'definition', 32))
            ?? throw new InvalidDefinitionException('definition.kind is not a supported game kind.');
        $generator = Doc::object($doc['generator'] ?? null, 'definition.generator');
        Doc::keys($generator, ['algorithm'], 'definition.generator');
        $algorithm = Doc::int($generator, 'algorithm', 'definition.generator', 1);
        if ($algorithm !== self::GENERATOR) {
            throw new InvalidDefinitionException("Unsupported generator algorithm {$algorithm}.");
        }
        $preset = Doc::object($doc['preset'] ?? null, 'definition.preset');
        Doc::keys($preset, ['name', 'version'], 'definition.preset');
        $source = Doc::object($doc['source'] ?? null, 'definition.source');
        Doc::keys($source, ['width', 'height', 'sha256'], 'definition.source');
        $sha = $source['sha256'] ?? null;
        if ($sha !== null && (!is_string($sha) || preg_match('/^[0-9a-f]{64}$/', $sha) !== 1)) {
            throw new InvalidDefinitionException('definition.source.sha256 must be null or 64 lowercase hex digits.');
        }
        $media = Doc::object($doc['media'] ?? null, 'definition.media');
        Doc::keys($media, ['image'], 'definition.media');
        $board = Doc::object($doc['board'] ?? null, 'definition.board');
        Doc::keys($board, ['rows', 'cols', 'crop'], 'definition.board');
        $tiles = [];
        foreach (Doc::list($doc['tiles'] ?? null, 'definition.tiles', self::MAX_TILES) as $i => $tile) {
            $tiles[] = Tile::fromArray($tile, "definition.tiles[{$i}]");
        }
        $assistance = Doc::object($doc['assistance'] ?? null, 'definition.assistance');
        Doc::keys($assistance, ['preview', 'numbers', 'highlightCorrect'], 'definition.assistance');
        $rules = ($doc['rules'] ?? null) === [] ? [] : Doc::object($doc['rules'] ?? null, 'definition.rules');

        $definition = new self(
            Doc::string($doc, 'id', 'definition', 80),
            $kind,
            Doc::int($doc, 'seed', 'definition', 0, Prng::MAX_SEED),
            Doc::string($preset, 'name', 'definition.preset', 32),
            Doc::int($preset, 'version', 'definition.preset', 1),
            Doc::int($source, 'width', 'definition.source', 1, 1 << 20),
            Doc::int($source, 'height', 'definition.source', 1, 1 << 20),
            $sha,
            Doc::string($media, 'image', 'definition.media'),
            Doc::int($board, 'rows', 'definition.board', 1, 1024),
            Doc::int($board, 'cols', 'definition.board', 1, 1024),
            Tile::rect($board['crop'] ?? null, 'definition.board.crop'),
            $tiles,
            $rules,
            [
                'preview' => Doc::bool($assistance, 'preview', 'definition.assistance'),
                'numbers' => Doc::bool($assistance, 'numbers', 'definition.assistance'),
                'highlightCorrect' => Doc::bool($assistance, 'highlightCorrect', 'definition.assistance'),
            ],
            [],
        );
        $definition->validateCommon();
        $mode = Modes::rules($kind);
        $mode->validateDefinition($definition);
        if (($doc['capabilities'] ?? null) !== $mode->capabilities()) {
            throw new InvalidDefinitionException('definition.capabilities do not match the ' . $kind->value . ' rules.');
        }
        if (($doc['actions'] ?? null) !== $mode->actionTypes()) {
            throw new InvalidDefinitionException('definition.actions do not match the ' . $kind->value . ' rules.');
        }
        $initial = $mode->validateBoard($definition, $doc['initial'] ?? null);
        if ($mode->isComplete($definition, $initial)) {
            throw new InvalidDefinitionException('definition.initial is already solved.');
        }
        $complete = new self($definition->id, $kind, $definition->seed, $definition->preset, $definition->presetVersion, $definition->sourceWidth, $definition->sourceHeight, $sha, $definition->image, $definition->rows, $definition->cols, $definition->crop, $tiles, $rules, $definition->assistance, $initial);
        $expected = $complete->computeId();
        if (!hash_equals($expected, $definition->id)) {
            throw new InvalidDefinitionException("definition.id does not match its content (expected {$expected}).");
        }

        return $complete;
    }

    public function rules(): ModeRules
    {
        return Modes::rules($this->kind);
    }

    public function tile(string $id): ?Tile
    {
        foreach ($this->tiles as $tile) {
            if ($tile->id === $id) {
                return $tile;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['id' => $this->id] + $this->content();
    }

    public function toJson(int $flags = JSON_PRETTY_PRINT): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Every field except the ID, in documented order.
     *
     * @return array<string, mixed>
     */
    private function content(): array
    {
        $mode = Modes::rules($this->kind);

        return [
            '$schema' => self::SCHEMA_ID,
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'kind' => $this->kind->value,
            'generator' => ['algorithm' => self::GENERATOR],
            'seed' => $this->seed,
            'preset' => ['name' => $this->preset, 'version' => $this->presetVersion],
            'source' => ['width' => $this->sourceWidth, 'height' => $this->sourceHeight, 'sha256' => $this->sourceSha256],
            'media' => ['image' => $this->image],
            'board' => ['rows' => $this->rows, 'cols' => $this->cols, 'crop' => $this->crop->toArray()],
            'tiles' => array_map(static fn (Tile $tile): array => $tile->toArray(), $this->tiles),
            'rules' => $this->rules === [] ? new \stdClass() : $this->rules,
            'actions' => $mode->actionTypes(),
            'capabilities' => $mode->capabilities(),
            'assistance' => $this->assistance,
            'initial' => $this->initial,
        ];
    }

    private function computeId(): string
    {
        $content = $this->content();
        unset($content['$schema']);

        return 'gd1-' . CanonicalJson::digest($content);
    }

    private function withId(): self
    {
        return new self($this->computeId(), $this->kind, $this->seed, $this->preset, $this->presetVersion, $this->sourceWidth, $this->sourceHeight, $this->sourceSha256, $this->image, $this->rows, $this->cols, $this->crop, $this->tiles, $this->rules, $this->assistance, $this->initial);
    }

    private function validateCommon(): void
    {
        if ($this->seed < 0 || $this->seed > Prng::MAX_SEED) {
            throw new InvalidDefinitionException('Seed must be an unsigned 32-bit integer.');
        }
        if (preg_match('/^(easy|medium|hard|custom)$/', $this->preset) !== 1) {
            throw new InvalidDefinitionException("Unknown preset \"{$this->preset}\".");
        }
        if (preg_match(self::MEDIA_PATTERN, $this->image) !== 1 || preg_match('~(^|/)\.\.?(/|$)~', $this->image) === 1) {
            throw new InvalidDefinitionException('media.image must be a relative path without dot segments or an https URL.');
        }
        $source = new Bounds(0, 0, max(1, $this->sourceWidth), max(1, $this->sourceHeight));
        if (!$source->contains($this->crop)) {
            throw new InvalidDefinitionException('board.crop must lie inside the source image.');
        }
        if (count($this->tiles) > self::MAX_TILES) {
            throw new InvalidDefinitionException('A definition holds at most ' . self::MAX_TILES . ' tiles.');
        }
        $seen = [];
        foreach ($this->tiles as $tile) {
            if (isset($seen[$tile->id])) {
                throw new InvalidDefinitionException("Duplicate tile id \"{$tile->id}\".");
            }
            $seen[$tile->id] = true;
        }
    }
}
