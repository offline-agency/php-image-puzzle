<?php

declare(strict_types=1);

namespace ImagePuzzle\Game;

use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Geometry\Bounds;
use ImagePuzzle\GeometryOptions;

/**
 * Reproducible, versioned input of DefinitionBuilder: mode, preset (or
 * custom parameters), seed and an optional crop of the upright source image
 * in pixels. Saving this document next to the source image is enough to
 * regenerate the same definition (docs/schemas/game/build-config.v1.schema.json).
 */
final readonly class BuildConfiguration
{
    public const FORMAT = 'image-puzzle/build-config';
    public const VERSION = 1;
    public const MAX_JSON_BYTES = 16384;

    /** Upper bound of crop coordinates and sizes in pixels (image budgets apply at build time). */
    public const MAX_CROP = 100000;

    /**
     * @param array<string, mixed> $custom parameters of the "custom" preset
     */
    public function __construct(
        public GameKind $kind,
        public string $preset = 'medium',
        public array $custom = [],
        public int $seed = 0,
        public ?Bounds $crop = null,
    ) {
        self::check($kind, $preset, $custom, $seed);
    }

    public static function fromJson(string $json): self
    {
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new InvalidDefinitionException('The build configuration is larger than ' . self::MAX_JSON_BYTES . ' bytes.');
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidDefinitionException('The build configuration is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        return self::fromArray($data);
    }

    public static function fromArray(mixed $data): self
    {
        $doc = Doc::object($data, 'config');
        Doc::keys($doc, ['$schema', 'format', 'version', 'kind', 'preset', 'custom', 'seed', 'crop'], 'config');
        if (($doc['format'] ?? null) !== self::FORMAT) {
            throw new InvalidDefinitionException('config.format must be "' . self::FORMAT . '".');
        }
        $version = Doc::int($doc, 'version', 'config', 1);
        if ($version !== self::VERSION) {
            throw new InvalidDefinitionException("Unsupported build configuration version {$version}; this library reads version " . self::VERSION . '.');
        }
        $kind = GameKind::tryFrom(Doc::string($doc, 'kind', 'config', 32));
        if ($kind === null || !in_array($kind, Modes::available(), true)) {
            throw new InvalidDefinitionException('config.kind must be one of ' . implode(', ', array_map(static fn (GameKind $k): string => $k->value, Modes::available())) . '.');
        }
        $crop = null;
        if (isset($doc['crop'])) {
            $c = Doc::object($doc['crop'], 'config.crop');
            Doc::keys($c, ['x', 'y', 'width', 'height'], 'config.crop');
            $crop = new Bounds(
                Doc::int($c, 'x', 'config.crop', 0, self::MAX_CROP),
                Doc::int($c, 'y', 'config.crop', 0, self::MAX_CROP),
                Doc::positive($c, 'width', 'config.crop', self::MAX_CROP),
                Doc::positive($c, 'height', 'config.crop', self::MAX_CROP),
            );
        }

        return new self(
            $kind,
            Doc::string($doc, 'preset', 'config', 32),
            isset($doc['custom']) ? Doc::object($doc['custom'], 'config.custom') : [],
            Doc::int($doc, 'seed', 'config', 0, Prng::MAX_SEED),
            $crop,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $doc = ['format' => self::FORMAT, 'version' => self::VERSION, 'kind' => $this->kind->value, 'preset' => $this->preset];
        if ($this->custom !== []) {
            $doc['custom'] = $this->custom;
        }
        $doc['seed'] = $this->seed;
        if ($this->crop !== null) {
            $doc['crop'] = $this->crop->toArray();
        }

        return $doc;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $custom
     */
    private static function check(GameKind $kind, string $preset, array $custom, int $seed): void
    {
        if ($preset !== 'custom' && !in_array($preset, Presets::NAMES, true)) {
            throw new InvalidDefinitionException('config.preset must be one of ' . implode(', ', Presets::NAMES) . ' or "custom".');
        }
        if ($preset !== 'custom' && $custom !== []) {
            throw new InvalidDefinitionException('config.custom requires the "custom" preset.');
        }
        if ($preset === 'custom') {
            if ($custom === []) {
                throw new InvalidDefinitionException('config.custom is required with the "custom" preset.');
            }
            Doc::keys($custom, GameFactory::customKeys($kind), 'config.custom');
        }
        // Jigsaw seeds also drive the outline geometry, which takes 31 bits.
        $max = $kind === GameKind::Jigsaw ? GeometryOptions::MAX_SEED : Prng::MAX_SEED;
        if ($seed < 0 || $seed > $max) {
            throw new InvalidDefinitionException("config.seed must be between 0 and {$max} for {$kind->value}, got {$seed}.");
        }
    }
}
