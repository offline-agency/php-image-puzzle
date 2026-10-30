<?php

declare(strict_types=1);

/*
 * Every ```php block in the maintained documentation that is not executed by
 * SnippetsTest is still checked here: it must parse (fragments such as array
 * entries or class members are wrapped first), every ImagePuzzle class,
 * interface or enum it names must exist, every static call and constant on
 * one must exist, and outside the framework guides every ->method() call must
 * be a public method of some ImagePuzzle class. Classes of the Laravel
 * integrations are resolved through their packages' PSR-4 maps without
 * loading Laravel; their behaviour is covered by the integration suites.
 */

/** Guides whose blocks call framework objects, so ->method() is not checked. */
const FRAMEWORK_DOCUMENTS = ['docs/LIVEWIRE.md', 'docs/FILAMENT.md', 'docs/CODEIGNITER.md'];

/** Integration namespaces, resolved by file because they extend Laravel classes. */
const INTEGRATION_NAMESPACES = ['ImagePuzzle\\Livewire\\' => 'integrations/livewire/src/', 'ImagePuzzle\\Filament\\' => 'integrations/filament/src/'];

/**
 * @return list<string>
 */
function documentedMarkdown(): array
{
    $repo = dirname(__DIR__, 2);
    $files = ['README.md', 'CONTRIBUTING.md'];
    foreach (['/docs/*.md', '/integrations/*/README.md', '/packages/*/README.md'] as $pattern) {
        $found = glob($repo . $pattern);
        foreach ($found === false ? [] : $found as $path) {
            $files[] = substr($path, strlen($repo) + 1);
        }
    }

    return $files;
}

/**
 * @return list<array{code: string, line: int}>
 */
function phpBlocks(string $document): array
{
    $text = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $document);
    preg_match_all('/^```php\R(.*?)\R```/sm', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    $found = [];
    foreach ($matches as $match) {
        $found[] = ['code' => $match[1][0], 'line' => substr_count(substr($text, 0, $match[0][1]), "\n") + 1];
    }

    return $found;
}

/**
 * Public method names of every class under src/, lower-cased, computed once.
 */
final class DocumentedApi
{
    /** @var array<string, true>|null */
    private static ?array $methods = null;

    /**
     * @return array<string, true>
     */
    public static function methods(): array
    {
        return self::$methods ??= libraryMethods();
    }
}

/**
 * @return array<string, true>
 */
function libraryMethods(): array
{
    $methods = [];
    $src = dirname(__DIR__, 2) . '/src';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $class = 'ImagePuzzle\\' . str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($src) + 1));
        $reflection = reflectType($class);
        if ($reflection === null) {
            continue;
        }
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $methods[strtolower($method->getName())] = true;
        }
    }

    return $methods;
}

function integrationFile(string $name): ?string
{
    foreach (INTEGRATION_NAMESPACES as $prefix => $directory) {
        if (str_starts_with($name, $prefix)) {
            return dirname(__DIR__, 2) . '/' . $directory . str_replace('\\', '/', substr($name, strlen($prefix))) . '.php';
        }
    }

    return null;
}

/**
 * @return ReflectionClass<object>|null
 */
function reflectType(string $name): ?ReflectionClass
{
    if (class_exists($name) || interface_exists($name) || enum_exists($name) || trait_exists($name)) {
        return new ReflectionClass($name);
    }

    return null;
}

function typeExists(string $name): bool
{
    $file = integrationFile($name);
    if ($file !== null) {
        return is_file($file);
    }

    return reflectType($name) !== null;
}

/**
 * Tokens of a block, wrapping fragments (array entries, class members) so
 * they parse; null when no form parses.
 *
 * @return list<PhpToken>|null
 */
function parseBlock(string $code, string &$error): ?array
{
    $body = (string) preg_replace('/^\s*<\?php\s*/', '', $code);
    foreach (["<?php\n{$body}", "<?php\n\$fragment = [\n{$body}\n];", "<?php\nclass DocumentedFragment {\n{$body}\n}"] as $index => $source) {
        try {
            $tokens = PhpToken::tokenize($source, TOKEN_PARSE);

            return array_values(array_filter($tokens, static fn (PhpToken $t): bool => !$t->isIgnorable()));
        } catch (ParseError $e) {
            if ($index === 0) {
                $error = $e->getMessage();
            }
        }
    }

    return null;
}

/**
 * @return list<string> problems found in one block
 */
function inspectBlock(string $code, bool $checkInstanceCalls): array
{
    $error = '';
    $significant = parseBlock($code, $error);
    if ($significant === null) {
        return ['does not parse: ' . $error];
    }

    $imports = [];
    $problems = [];
    $import = static function (string $name, ?string $alias) use (&$imports, &$checkInstanceCalls): void {
        $name = ltrim($name, '\\');
        $imports[strtolower($alias ?? substr($name, (int) strrpos('\\' . $name, '\\')))] = $name;
        if (!str_starts_with($name, 'ImagePuzzle\\')) {
            $checkInstanceCalls = false;
        }
    };
    $names = [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING];
    foreach ($significant as $i => $token) {
        $first = $significant[$i + 1] ?? null;
        if (!$token->is(T_USE) || $first === null || !$first->is($names) || ($significant[$i - 1] ?? null)?->text === ')') {
            continue;
        }
        if (($significant[$i + 2] ?? null)?->is(T_NS_SEPARATOR) === true && ($significant[$i + 3] ?? null)?->text === '{') {
            for ($j = $i + 4; isset($significant[$j]) && $significant[$j]->text !== '}'; $j++) {
                if ($significant[$j]->is($names) && ($significant[$j - 1]->text === '{' || $significant[$j - 1]->text === ',')) {
                    $as = ($significant[$j + 1] ?? null)?->is(T_AS) === true ? ($significant[$j + 2] ?? null)?->text : null;
                    $import($first->text . '\\' . $significant[$j]->text, $as);
                }
            }

            continue;
        }
        $as = ($significant[$i + 2] ?? null)?->is(T_AS) === true ? ($significant[$i + 3] ?? null)?->text : null;
        $import($first->text, $as);
    }
    $resolve = static function (string $name) use ($imports): string {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }
        $first = strtolower(explode('\\', $name)[0]);
        if (isset($imports[$first])) {
            return $imports[$first] . substr($name, strlen($first));
        }

        return $name;
    };

    foreach ($imports as $name) {
        if (str_starts_with($name, 'ImagePuzzle\\') && !typeExists($name) && !function_exists($name)) {
            $problems[] = "imports unknown {$name}";
        }
    }
    foreach ($significant as $i => $token) {
        $next = $significant[$i + 1] ?? null;
        $previous = $significant[$i - 1] ?? null;
        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && $next !== null && $next->is(T_DOUBLE_COLON)) {
            $class = $resolve($token->text);
            $member = $significant[$i + 2] ?? null;
            if (!str_starts_with($class, 'ImagePuzzle\\') || $member === null) {
                continue;
            }
            if (!typeExists($class)) {
                $problems[] = "uses unknown {$class}";

                continue;
            }
            $reflection = reflectType($class);
            if ($member->is(T_CLASS) || $reflection === null) {
                continue;
            }
            $call = ($significant[$i + 3] ?? null)?->text === '(';
            if ($call && !$reflection->hasMethod($member->text)) {
                $problems[] = "calls unknown {$class}::{$member->text}()";
            } elseif (!$call && $member->is(T_STRING) && !$reflection->hasConstant($member->text)) {
                $problems[] = "uses unknown {$class}::{$member->text}";
            }
        }
        if ($token->is(T_NEW) && $next !== null && $next->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            $class = $resolve($next->text);
            if (str_starts_with($class, 'ImagePuzzle\\') && !typeExists($class)) {
                $problems[] = "instantiates unknown {$class}";
            }
        }
        if ($checkInstanceCalls && $token->is(T_OBJECT_OPERATOR) && $next !== null && $next->is(T_STRING) && ($significant[$i + 2] ?? null)?->text === '(' && $previous !== null) {
            if (!isset(DocumentedApi::methods()[strtolower($next->text)])) {
                $problems[] = "calls ->{$next->text}(), which no ImagePuzzle class has";
            }
        }
    }

    return $problems;
}

it('keeps every documented PHP block valid against the shipped API', function (string $document): void {
    $problems = [];
    foreach (phpBlocks($document) as $block) {
        foreach (inspectBlock($block['code'], !in_array($document, FRAMEWORK_DOCUMENTS, true)) as $problem) {
            $problems[] = "{$document}:{$block['line']} {$problem}";
        }
    }

    expect($problems)->toBe([]);
})->with(documentedMarkdown());

it('reports blocks that drift from the API', function (string $code, string $problem): void {
    expect(implode("\n", inspectBlock($code, true)))->toContain($problem);
})->with([
    'unknown import' => ['use ImagePuzzle\Nope;', 'imports unknown ImagePuzzle\Nope'],
    'unknown grouped import' => ['use ImagePuzzle\Game\{GameKind, Nope};', 'imports unknown ImagePuzzle\Game\Nope'],
    'unknown static method' => ["use ImagePuzzle\\Game\\Modes;\nModes::nope();", 'calls unknown ImagePuzzle\Game\Modes::nope()'],
    'unknown enum case' => ["use ImagePuzzle\\Game\\GameKind;\n\$kind = GameKind::Chess;", 'uses unknown ImagePuzzle\Game\GameKind::Chess'],
    'unknown instance method' => ['$puzzle->exportEverything();', 'calls ->exportEverything()'],
    'unknown integration class' => ['use ImagePuzzle\Filament\Nope;', 'imports unknown ImagePuzzle\Filament\Nope'],
    'syntax error' => ['$a = ;', 'does not parse'],
]);

it('accepts fragments and the current API', function (string $code): void {
    expect(inspectBlock($code, true))->toBe([]);
})->with([
    'array entries' => ["'kind' => 'jigsaw',\n'seed' => 4,"],
    'class members' => ["public function play(): void\n{\n}"],
    'library calls' => ["use ImagePuzzle\\Game\\{GameEngine, GameKind};\n\$engine = new GameEngine();\n\$engine->start(\$definition);\n\$kind = GameKind::Jigsaw;"],
]);
