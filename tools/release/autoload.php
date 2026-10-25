<?php

declare(strict_types=1);

/*
 * Autoloader for the release tooling. The publishing job runs these scripts
 * with a write token and must not install or execute Composer packages, so
 * the tooling depends on nothing but PHP itself (curl and json).
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'ImagePuzzle\\Release\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
