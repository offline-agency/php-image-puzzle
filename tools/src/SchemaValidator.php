<?php

declare(strict_types=1);

namespace ImagePuzzle\Tools;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates JSON documents against the published schemas, resolved from the
 * local docs/schemas directory (no network access).
 */
final class SchemaValidator
{
    public const ID_PREFIX = 'https://raw.githubusercontent.com/offline-agency/php-image-puzzle/HEAD/docs/schemas/';

    private readonly Validator $validator;

    public function __construct(private readonly string $schemaDir)
    {
        $this->validator = new Validator();
        $this->validator->setMaxErrors(10);
        $this->validator->resolver()?->registerPrefix(self::ID_PREFIX, rtrim($schemaDir, '/') . '/');
    }

    public static function forRepository(): self
    {
        return new self(dirname(__DIR__, 2) . '/docs/schemas');
    }

    /**
     * Problems with the schema files themselves: unparsable JSON or an $id
     * that does not match the file location.
     *
     * @return list<string>
     */
    public function checkSchemaFiles(): array
    {
        $problems = [];
        $files = glob(rtrim($this->schemaDir, '/') . '/*/*.schema.json') ?: [];
        foreach ($files as $file) {
            $relative = substr($file, strlen(rtrim($this->schemaDir, '/')) + 1);
            try {
                $schema = json_decode((string) file_get_contents($file), false, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $problems[] = "{$relative}: invalid JSON ({$e->getMessage()})";
                continue;
            }
            if (!$schema instanceof \stdClass || !isset($schema->{'$id'}) || $schema->{'$id'} !== self::ID_PREFIX . $relative) {
                $problems[] = "{$relative}: \$id must be " . self::ID_PREFIX . $relative;
            }
        }
        if ($files === []) {
            $problems[] = 'No schema files found.';
        }

        return $problems;
    }

    /**
     * @return list<string> validation errors; empty when the document is valid
     */
    public function validate(mixed $document, string $schemaPath): array
    {
        // PHP arrays cannot tell {} from []: callers holding the original
        // JSON should use validateFile(), which keeps the distinction.
        $data = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        return $this->validateDecoded($data, $schemaPath);
    }

    /**
     * @return list<string>
     */
    private function validateDecoded(mixed $data, string $schemaPath): array
    {
        try {
            $result = $this->validator->validate($data, self::ID_PREFIX . $schemaPath);
        } catch (\Opis\JsonSchema\Exceptions\SchemaException $e) {
            return ["schema {$schemaPath} is invalid: {$e->getMessage()}"];
        }
        $error = $result->error();
        if ($error === null) {
            return [];
        }
        $messages = [];
        foreach ((new ErrorFormatter())->format($error, true) as $pointer => $list) {
            foreach ((array) $list as $message) {
                $messages[] = ($pointer === '' ? '/' : $pointer) . ': ' . (is_string($message) ? $message : json_encode($message));
            }
        }

        return $messages;
    }

    /**
     * Validate a JSON file against the schema named by its "$schema" key, or
     * $fallbackSchema when it has none.
     *
     * @return list<string>
     */
    public function validateFile(string $file, ?string $fallbackSchema = null): array
    {
        try {
            $document = json_decode((string) file_get_contents($file), false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ["invalid JSON ({$e->getMessage()})"];
        }
        $declared = $document instanceof \stdClass ? ($document->{'$schema'} ?? null) : null;
        $schema = is_string($declared) && str_starts_with($declared, self::ID_PREFIX)
            ? substr($declared, strlen(self::ID_PREFIX))
            : $fallbackSchema;
        if ($schema === null) {
            return ['no $schema and no fallback schema given'];
        }

        return $this->validateDecoded($document, $schema);
    }
}
