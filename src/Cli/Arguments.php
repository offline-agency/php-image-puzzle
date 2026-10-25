<?php

declare(strict_types=1);

namespace ImagePuzzle\Cli;

/**
 * Parsed command line of one command: positional arguments and long
 * options (--name=value, --name value, --flag). Options a command does not
 * declare are usage errors; "--" ends option parsing.
 */
final class Arguments
{
    /**
     * @param list<string> $positional
     * @param array<string, list<string>> $values
     * @param array<string, true> $flags
     */
    private function __construct(
        public readonly array $positional,
        private readonly array $values,
        private readonly array $flags,
    ) {
    }

    /**
     * @param list<string> $argv arguments after the command name
     * @param list<string> $valueOptions options that take a value
     * @param list<string> $flagOptions options without a value
     * @param list<string> $repeatable value options that may be repeated
     */
    public static function parse(array $argv, array $valueOptions, array $flagOptions, array $repeatable = []): self
    {
        $positional = [];
        $values = [];
        $flags = [];
        $count = count($argv);
        for ($i = 0; $i < $count; $i++) {
            $arg = $argv[$i];
            if ($arg === '--') {
                array_push($positional, ...array_slice($argv, $i + 1));
                break;
            }
            if (!str_starts_with($arg, '--') || $arg === '--') {
                $positional[] = $arg;
                continue;
            }
            [$name, $value] = str_contains($arg, '=') ? explode('=', substr($arg, 2), 2) : [substr($arg, 2), null];
            if (in_array($name, $flagOptions, true)) {
                if ($value !== null) {
                    throw new UsageException("--{$name} does not take a value.");
                }
                $flags[$name] = true;
                continue;
            }
            if (!in_array($name, $valueOptions, true)) {
                throw new UsageException("Unknown option --{$name}.");
            }
            if ($value === null) {
                if ($i + 1 >= $count) {
                    throw new UsageException("--{$name} needs a value.");
                }
                $value = $argv[++$i];
            }
            if (isset($values[$name]) && !in_array($name, $repeatable, true)) {
                throw new UsageException("--{$name} is given more than once.");
            }
            $values[$name][] = $value;
        }

        return new self($positional, $values, $flags);
    }

    public function flag(string $name): bool
    {
        return isset($this->flags[$name]);
    }

    public function value(string $name): ?string
    {
        return $this->values[$name][0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function all(string $name): array
    {
        return $this->values[$name] ?? [];
    }

    public function int(string $name, int $min, int $max): ?int
    {
        $value = $this->value($name);
        if ($value === null) {
            return null;
        }
        if (preg_match('/^-?[0-9]{1,10}$/', $value) !== 1 || (int) $value < $min || (int) $value > $max) {
            throw new UsageException("--{$name} must be an integer from {$min} to {$max}.");
        }

        return (int) $value;
    }

    public function float(string $name): ?float
    {
        $value = $this->value($name);
        if ($value === null) {
            return null;
        }
        if (preg_match('/^[0-9]{1,6}(\.[0-9]{1,4})?$/', $value) !== 1) {
            throw new UsageException("--{$name} must be a positive number.");
        }

        return (float) $value;
    }

    /**
     * @param list<string> $allowed
     */
    public function choice(string $name, array $allowed): ?string
    {
        $value = $this->value($name);
        if ($value !== null && !in_array($value, $allowed, true)) {
            throw new UsageException("--{$name} must be one of: " . implode(', ', $allowed) . '.');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public function expect(int $min, int $max, string $synopsis): array
    {
        $count = count($this->positional);
        if ($count < $min || $count > $max) {
            throw new UsageException("Usage: image-puzzle {$synopsis}");
        }

        return $this->positional;
    }
}
