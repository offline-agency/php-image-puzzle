<?php

declare(strict_types=1);

namespace Tests\ImagePuzzle;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\ImagePuzzle;
use Config\Services;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Exception\ResourceLimitExceededException;
use ImagePuzzle\Game\BuildConfiguration;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameEngine;
use ImagePuzzle\Game\GameKind;

/**
 * The example application end to end, without a web server: the service
 * builds into configured directories only, and the routes render the
 * player, return stored progress and accept only valid saved games.
 * tools/codeigniter/smoke.php repeats the upload flow over real HTTP.
 */
final class PuzzleFlowTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/ci4-image-puzzle-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/uploads', 0o700, true);
        $config = config(ImagePuzzle::class);
        $config->publicDirectory = $this->root . '/public';
        $config->progressDirectory = $this->root . '/progress';
        $config->uploadDirectory = $this->root . '/uploads';
        $config->maxWidth = 1000;
        $config->maxHeight = 1000;
        Services::resetSingle('puzzles');
        Services::resetSingle('puzzleBuilder');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
        rmdir($this->root);
        parent::tearDown();
    }

    private function photo(int $width = 240, int $height = 180): string
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        for ($x = 0; $x < $width; $x += 20) {
            imagefilledrectangle($image, $x, 0, $x + 19, $height - 1, (int) imagecolorallocate($image, $x % 256, 255 - $x % 256, 120));
        }
        $path = $this->root . '/uploads/' . bin2hex(random_bytes(4)) . '.img';
        imagepng($image, $path);

        return $path;
    }

    /**
     * @return list<string>
     */
    private static function entries(string $dir): array
    {
        $found = glob($dir . '/*');

        return $found === false ? [] : $found;
    }

    private function build(string $kind = 'swap'): string
    {
        return Services::puzzles()->create($this->photo(), new BuildConfiguration(GameKind::from($kind), 'easy', [], 3));
    }

    /**
     * @return array<string, mixed>
     */
    private function playedState(string $id, int $moves): array
    {
        $definition = Services::puzzles()->definition($id);
        $engine = new GameEngine();
        $state = $engine->start($definition);
        for ($i = 0; $i < $moves; $i++) {
            $state = $engine->apply($definition, $state, $definition->rules()->legalActions($definition, $state->board)[0]);
        }

        return $state->toArray();
    }

    public function testBuildsIntoTheConfiguredPublicDirectory(): void
    {
        $id = $this->build('jigsaw');

        $this->assertMatchesRegularExpression('/^p[0-9a-f]{16}$/', $id);
        $this->assertFileExists($this->root . '/public/' . $id . '/definition.json');
        $this->assertSame(GameKind::Jigsaw, Services::puzzles()->definition($id)->kind);
    }

    public function testRejectsOversizedImagesAndLeavesNothingBehind(): void
    {
        $this->expectException(ResourceLimitExceededException::class);
        try {
            Services::puzzles()->create($this->photo(1200, 200), new BuildConfiguration(GameKind::Swap, 'easy'));
        } finally {
            $this->assertSame([], self::entries($this->root . '/public'));
        }
    }

    public function testRequestIdsCannotLeaveThePuzzleDirectory(): void
    {
        $this->expectException(PageNotFoundException::class);
        Services::puzzles()->definition('../../app/Config/App');
    }

    public function testShowsThePlayerForABuiltPuzzle(): void
    {
        $id = $this->build();

        $result = $this->get('play/' . $id);

        $result->assertOK();
        $result->assertSee('<image-puzzle-player id="game" src="');
        $result->assertSee('puzzles/' . $id . '/definition.json');
    }

    public function testUnknownOrMalformedPuzzlesAreNotFound(): void
    {
        $this->expectException(PageNotFoundException::class);
        $this->get('play/p0000000000000000');
    }

    public function testStoresValidatedProgressAndReturnsIt(): void
    {
        $id = $this->build();
        $this->get('play/' . $id . '/progress')->assertStatus(204);

        $state = $this->playedState($id, 2);
        $saved = $this->withBody((string) json_encode($state))->post('play/' . $id . '/progress');
        $saved->assertOK();
        $saved->assertJSONFragment(['moves' => 2, 'completed' => false]);

        $restored = $this->get('play/' . $id . '/progress');
        $restored->assertOK();
        $document = json_decode((string) $restored->getJSON(), true);
        $this->assertIsArray($document);
        $this->assertSame(GameDefinition::fromJson((string) file_get_contents($this->root . '/public/' . $id . '/definition.json'))->id, $document['definitionId'] ?? null);
    }

    public function testRejectsAnotherPuzzlesStateAndMalformedBodies(): void
    {
        $id = $this->build();
        $other = $this->build('rotation');

        $this->withBody((string) json_encode($this->playedState($other, 1)))->post('play/' . $id . '/progress')->assertStatus(422);
        $this->withBody('{not json')->post('play/' . $id . '/progress')->assertStatus(422);
        $this->assertNull(Services::puzzles()->progress($id));

        $this->expectException(InvalidDefinitionException::class);
        Services::puzzles()->saveProgress($id, ['format' => 'nope']);
    }

    public function testTheFormRequiresAPhotoAndAKnownMode(): void
    {
        $form = $this->get('/');
        $form->assertOK();
        $form->assertSee('Build the puzzle');

        $result = $this->post('play', ['kind' => 'tetris', 'preset' => 'easy']);
        $result->assertStatus(422);
        $result->assertSee('role="alert"');
        $this->assertSame([], self::entries($this->root . '/public'));
    }
}
