<?php

declare(strict_types=1);

namespace ImagePuzzle\Livewire\Tests;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Foundation\Application;
use ImagePuzzle\Game\BuildResult;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Livewire\ImagePuzzleServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @var array<string, BuildResult> */
    private static array $built = [];

    private static ?string $root = null;

    /**
     * Definitions built once per run with the core DefinitionBuilder.
     */
    public static function puzzle(string $kind): BuildResult
    {
        if (!isset(self::$built[$kind])) {
            self::$root ??= sys_get_temp_dir() . '/image-puzzle-livewire-' . bin2hex(random_bytes(6));
            $source = self::$root . '/source.png';
            if (!is_file($source)) {
                @mkdir(self::$root, 0700, true);
                $image = imagecreatetruecolor(120, 90);
                for ($x = 0; $x < 120; $x += 10) {
                    imagefilledrectangle($image, $x, 0, $x + 9, 89, (int) imagecolorallocate($image, $x * 2, 255 - $x * 2, 128));
                }
                imagepng($image, $source);
            }
            self::$built[$kind] = (new DefinitionBuilder())->build($source, self::$root . '/' . $kind, GameKind::from($kind), 'easy', seed: 5);
        }

        return self::$built[$kind];
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, ImagePuzzleServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app->make(Config::class);
        $config->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $config->set('image-puzzle.puzzles', [
            'swap' => self::puzzle('swap')->definitionPath(),
            'jigsaw' => self::puzzle('jigsaw')->definitionPath(),
        ]);
    }
}
