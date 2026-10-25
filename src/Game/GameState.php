<?php

declare(strict_types=1);

namespace ImagePuzzle\Game;

use ImagePuzzle\Exception\InvalidDefinitionException;

/**
 * Player state for one definition (docs/schemas/game/v1/state.schema.json).
 *
 * Counters: "moves" counts every state-changing player operation (actions,
 * undo and redo); "elapsedMs" is active play time measured by the client and
 * never advances while paused; "hintsUsed" counts revealed hints. History
 * keeps at most HISTORY_LIMIT previous ("past") and undone ("future") boards.
 *
 * A client-reported completion is an interaction signal, not proof: servers
 * re-validate with fromArray(), which recomputes "completed".
 */
final readonly class GameState
{
    public const FORMAT = 'image-puzzle/game-state';
    public const VERSION = 1;
    public const HISTORY_LIMIT = 100;
    public const MAX_JSON_BYTES = 8 * 1024 * 1024;
    public const SCHEMA_ID = 'https://raw.githubusercontent.com/offline-agency/php-image-puzzle/HEAD/docs/schemas/game/v1/state.schema.json';

    /**
     * @param array<string, mixed> $board
     * @param list<array<string, mixed>> $past oldest first
     * @param list<array<string, mixed>> $future next redo last
     */
    public function __construct(
        public string $definitionId,
        public GameKind $kind,
        public array $board,
        public int $moves = 0,
        public int $elapsedMs = 0,
        public int $hintsUsed = 0,
        public bool $completed = false,
        public array $past = [],
        public array $future = [],
    ) {
    }

    public static function fromJson(GameDefinition $definition, string $json): self
    {
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new InvalidDefinitionException('Game state exceeds ' . self::MAX_JSON_BYTES . ' bytes.');
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidDefinitionException('Game state is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        return self::fromArray($definition, $data);
    }

    /**
     * Validates an imported state against its definition. Stale versions,
     * another definition's state and unreachable boards are rejected.
     */
    public static function fromArray(GameDefinition $definition, mixed $data): self
    {
        $doc = Doc::object($data, 'state');
        Doc::keys($doc, ['$schema', 'format', 'version', 'definitionId', 'kind', 'board', 'moves', 'elapsedMs', 'hintsUsed', 'completed', 'history'], 'state');
        if (($doc['format'] ?? null) !== self::FORMAT) {
            throw new InvalidDefinitionException('state.format must be "' . self::FORMAT . '".');
        }
        $version = Doc::int($doc, 'version', 'state', 1);
        if ($version !== self::VERSION) {
            throw new InvalidDefinitionException("Unsupported game state version {$version}; this library reads version " . self::VERSION . '.');
        }
        if (Doc::string($doc, 'definitionId', 'state', 80) !== $definition->id) {
            throw new InvalidDefinitionException('state.definitionId belongs to another puzzle.');
        }
        if (Doc::string($doc, 'kind', 'state', 32) !== $definition->kind->value) {
            throw new InvalidDefinitionException('state.kind does not match the definition.');
        }
        $rules = $definition->rules();
        $board = $rules->validateBoard($definition, $doc['board'] ?? null);
        $completed = Doc::bool($doc, 'completed', 'state');
        if ($completed !== $rules->isComplete($definition, $board)) {
            throw new InvalidDefinitionException('state.completed does not match the board.');
        }
        $history = Doc::object($doc['history'] ?? null, 'state.history');
        Doc::keys($history, ['past', 'future'], 'state.history');
        $boards = [];
        foreach (['past', 'future'] as $key) {
            $boards[$key] = [];
            foreach (Doc::list($history[$key] ?? null, "state.history.{$key}", self::HISTORY_LIMIT) as $entry) {
                $boards[$key][] = $rules->validateBoard($definition, $entry);
            }
        }

        return new self(
            $definition->id,
            $definition->kind,
            $board,
            Doc::int($doc, 'moves', 'state', 0, PHP_INT_MAX),
            Doc::int($doc, 'elapsedMs', 'state', 0, PHP_INT_MAX),
            Doc::int($doc, 'hintsUsed', 'state', 0, PHP_INT_MAX),
            $completed,
            $boards['past'],
            $boards['future'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            '$schema' => self::SCHEMA_ID,
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'definitionId' => $this->definitionId,
            'kind' => $this->kind->value,
            'board' => $this->board,
            'moves' => $this->moves,
            'elapsedMs' => $this->elapsedMs,
            'hintsUsed' => $this->hintsUsed,
            'completed' => $this->completed,
            'history' => ['past' => $this->past, 'future' => $this->future],
        ];
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->toArray(), $flags | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
