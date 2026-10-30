<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament\Rules;

use Illuminate\Contracts\Validation\ValidationRule;
use ImagePuzzle\Exception\ImagePuzzleException;
use ImagePuzzle\Game\BuildConfiguration;

/**
 * Validates a build configuration document with the PHP rules (the browser
 * configurator only mirrors them) and restricts the offered modes.
 */
final readonly class ValidBuildConfiguration implements ValidationRule
{
    /**
     * @param list<string> $kinds allowed modes; empty allows every buildable mode
     */
    public function __construct(private array $kinds = [])
    {
    }

    public function validate(string $attribute, mixed $value, \Closure $fail): void
    {
        if ($value === null) {
            return;
        }
        try {
            $config = is_string($value) ? BuildConfiguration::fromJson($value) : BuildConfiguration::fromArray($value);
        } catch (ImagePuzzleException $e) {
            $fail($e->getMessage());

            return;
        }
        if ($this->kinds !== [] && !in_array($config->kind->value, $this->kinds, true)) {
            $fail(__('image-puzzle-filament::messages.kind_not_allowed', ['kind' => $config->kind->value]));
        }
    }
}
