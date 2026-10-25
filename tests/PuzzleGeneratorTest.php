<?php

declare(strict_types=1);

use ImagePuzzle\EdgeStyle\JigsawStyle;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\UnsupportedImageTypeException;
use ImagePuzzle\ImageInfo;
use ImagePuzzle\ImageLoader;
use ImagePuzzle\ImageLoaderInterface;
use ImagePuzzle\Puzzle;
use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\Tests\Support\Fixtures;

test('generateFromFile produces correct number of pieces', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    expect($puzzle)->toBeInstanceOf(Puzzle::class);
    expect($puzzle->getRows())->toBe(2);
    expect($puzzle->getCols())->toBe(2);
    $count = 0;
    foreach ($puzzle->getPieces() as $piece) {
        $count++;
    }
    expect($count)->toBe(4);
});

test('piece dimensions sum to source with remainder on last row and col', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $width = $puzzle->getWidth();
    $height = $puzzle->getHeight();
    $sumWidth = 0;
    $sumHeight = 0;
    foreach ($puzzle->getPieces() as $piece) {
        $sumWidth += $piece->getWidth();
        $sumHeight += $piece->getHeight();
    }
    expect($sumWidth)->toBe($width * 2);
    expect($sumHeight)->toBe($height * 2);
    expect($puzzle->getPiece(0, 0)->getWidth() + $puzzle->getPiece(0, 1)->getWidth())->toBe($width);
    expect($puzzle->getPiece(0, 0)->getHeight() + $puzzle->getPiece(1, 0)->getHeight())->toBe($height);
});

test('generateFromFile with JPEG', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.jpg', 2, 2);
    expect($puzzle->getRows())->toBe(2);
    expect($puzzle->getCols())->toBe(2);
    expect($puzzle->getWidth())->toBe(20);
    expect($puzzle->getHeight())->toBe(20);
});

test('invalid rows throws', function (): void {
    $generator = new PuzzleGenerator();
    expect(fn () => $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 0, 2))
        ->toThrow(InvalidArgumentException::class, 'Rows must be at least 1');
});

test('invalid cols throws', function (): void {
    $generator = new PuzzleGenerator();
    expect(fn () => $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 0))
        ->toThrow(InvalidArgumentException::class, 'Cols must be at least 1');
});

test('unreadable file throws', function (): void {
    $generator = new PuzzleGenerator();
    expect(fn () => $generator->generateFromFile(__DIR__ . '/fixtures/nonexistent.png', 2, 2))
        ->toThrow(InvalidArgumentException::class, 'not readable');
});

test('unsupported image type throws', function (): void {
    $generator = new PuzzleGenerator();
    $tmp = sys_get_temp_dir() . '/image-puzzle-test-' . uniqid() . '.bmp';
    file_put_contents($tmp, 'invalid');
    try {
        expect(fn () => $generator->generateFromFile($tmp, 1, 1))
            ->toThrow(UnsupportedImageTypeException::class);
    } finally {
        @unlink($tmp);
    }
});

test('getPiece returns correct piece', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $piece = $puzzle->getPiece(0, 1);
    expect($piece->getRow())->toBe(0);
    expect($piece->getCol())->toBe(1);
    expect($piece->getX())->toBe(16);
    expect($piece->getY())->toBe(0);
});

test('getPiece out of range throws', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    expect(fn () => $puzzle->getPiece(2, 0))
        ->toThrow(InvalidArgumentException::class, 'Invalid piece index');
});

test('generateFromGd produces puzzle from GdImage', function (): void {
    $image = Fixtures::load(__DIR__ . '/fixtures/sample.png');
    expect($image)->not->toBeFalse();
    $expectedWidth = imagesx($image);
    $expectedHeight = imagesy($image);
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromGd($image, 2, 2);
    expect($puzzle->getRows())->toBe(2);
    expect($puzzle->getCols())->toBe(2);
    expect($puzzle->getWidth())->toBe($expectedWidth);
    expect($puzzle->getHeight())->toBe($expectedHeight);
    $count = 0;
    foreach ($puzzle->getPieces() as $piece) {
        $count++;
    }
    expect($count)->toBe(4);
});

test('generateFromGd with invalid rows throws', function (): void {
    $image = Fixtures::load(__DIR__ . '/fixtures/sample.png');
    expect($image)->not->toBeFalse();
    $generator = new PuzzleGenerator();
    expect(fn () => $generator->generateFromGd($image, 0, 2))
        ->toThrow(InvalidArgumentException::class, 'Rows must be at least 1');
});

test('generateFromGd with invalid cols throws', function (): void {
    $image = Fixtures::load(__DIR__ . '/fixtures/sample.png');
    expect($image)->not->toBeFalse();
    $generator = new PuzzleGenerator();
    expect(fn () => $generator->generateFromGd($image, 2, 0))
        ->toThrow(InvalidArgumentException::class, 'Cols must be at least 1');
});

test('generateFromFile 1x1 produces single piece', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    expect($puzzle->getRows())->toBe(1);
    expect($puzzle->getCols())->toBe(1);
    $piece = $puzzle->getPiece(0, 0);
    expect($piece->getWidth())->toBe($puzzle->getWidth());
    expect($piece->getHeight())->toBe($puzzle->getHeight());
});

test('generateFromFile with custom EdgeStyle uses it', function (): void {
    $style = new \ImagePuzzle\EdgeStyle\RoundedCornersStyle(2);
    $generator = new PuzzleGenerator($style);
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    $piece = $puzzle->getPiece(0, 0);
    $image = $piece->toGdImage();
    expect($image)->toBeInstanceOf(GdImage::class);
    expect(imagesx($image))->toBe($piece->getWidth());
    expect(imagesy($image))->toBe($piece->getHeight());
});

test('generateFromFile 3x4 grid produces 12 pieces', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 3, 4);
    expect($puzzle->getRows())->toBe(3);
    expect($puzzle->getCols())->toBe(4);
    $count = 0;
    foreach ($puzzle->getPieces() as $piece) {
        $count++;
    }
    expect($count)->toBe(12);
});

test('generatePiecesFromGd returns only requested pieces', function (): void {
    $image = Fixtures::load(__DIR__ . '/fixtures/sample.png');
    expect($image)->not->toBeFalse();
    $generator = new PuzzleGenerator();
    $pieces = $generator->generatePiecesFromGd($image, 2, 2, [[0, 0], [1, 1]]);
    expect($pieces)->toHaveCount(2);
    expect($pieces[0]->getRow())->toBe(0);
    expect($pieces[0]->getCol())->toBe(0);
    expect($pieces[1]->getRow())->toBe(1);
    expect($pieces[1]->getCol())->toBe(1);
});

test('generatePiecesFromFile returns same piece data as full puzzle', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $indices = [[0, 0], [0, 1], [1, 0], [1, 1]];
    $pieces = $generator->generatePiecesFromFile(__DIR__ . '/fixtures/sample.png', 2, 2, $indices);
    expect($pieces)->toHaveCount(4);
    foreach ($indices as $i => [$row, $col]) {
        expect($pieces[$i]->getRow())->toBe($row);
        expect($pieces[$i]->getCol())->toBe($col);
        $fullPiece = $puzzle->getPiece($row, $col);
        expect($pieces[$i]->getWidth())->toBe($fullPiece->getWidth());
        expect($pieces[$i]->getHeight())->toBe($fullPiece->getHeight());
        expect($pieces[$i]->getX())->toBe($fullPiece->getX());
        expect($pieces[$i]->getY())->toBe($fullPiece->getY());
    }
});

test('generatePiecesFromFile with unreadable path throws', function (): void {
    $generator = new PuzzleGenerator();
    expect(fn () => $generator->generatePiecesFromFile(__DIR__ . '/fixtures/nonexistent.png', 2, 2, [[0, 0]]))
        ->toThrow(InvalidArgumentException::class, 'not readable');
});

test('generatePiecesFromGd with empty indices returns empty array', function (): void {
    $image = Fixtures::load(__DIR__ . '/fixtures/sample.png');
    expect($image)->not->toBeFalse();
    $generator = new PuzzleGenerator();
    $pieces = $generator->generatePiecesFromGd($image, 2, 2, []);
    expect($pieces)->toBe([]);
});

test('generateAndExport with invalid path throws', function (): void {
    $outDir = sys_get_temp_dir() . '/image-puzzle-invalid-' . uniqid();
    expect(fn () => PuzzleGenerator::generateAndExport(
        __DIR__ . '/fixtures/nonexistent.png',
        $outDir,
        2,
        2,
    ))->toThrow(InvalidArgumentException::class, 'not readable');
});

test('generateAndExport with edge style and custom ExportOptions', function (): void {
    $outDir = sys_get_temp_dir() . '/image-puzzle-style-opts-' . uniqid();
    $options = new \ImagePuzzle\ExportOptions(manifestFilename: 'custom-manifest.json');
    $style = new \ImagePuzzle\EdgeStyle\RoundedCornersStyle(2);
    $result = PuzzleGenerator::generateAndExport(
        __DIR__ . '/fixtures/sample.png',
        $outDir,
        1,
        1,
        $style,
        $options,
    );
    expect($result->getManifestPath())->toEndWith('custom-manifest.json');
    expect($result->getFiles())->toHaveCount(2);
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('generateAndExport produces same result as three-step flow', function (): void {
    $outDirOneShot = sys_get_temp_dir() . '/image-puzzle-one-shot-' . uniqid();
    $outDirThree = sys_get_temp_dir() . '/image-puzzle-three-' . uniqid();
    $options = new \ImagePuzzle\ExportOptions();
    $resultOneShot = PuzzleGenerator::generateAndExport(
        __DIR__ . '/fixtures/sample.png',
        $outDirOneShot,
        2,
        2,
        null,
        $options,
    );
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $resultThree = $puzzle->export($outDirThree, $options);
    expect($resultOneShot->getFiles())->toHaveCount(count($resultThree->getFiles()));
    expect($resultOneShot->getManifest()['rows'])->toBe($resultThree->getManifest()['rows']);
    expect($resultOneShot->getManifest()['cols'])->toBe($resultThree->getManifest()['cols']);
    expect(Fixtures::pieces($resultOneShot->getManifest()))->toHaveCount(count(Fixtures::pieces($resultThree->getManifest())));
    foreach ($resultOneShot->getFiles() as $f) {
        @unlink($f);
    }
    foreach ($resultThree->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDirOneShot);
    @rmdir($outDirThree);
});

test('an injected ImageLoaderInterface does the inspection and the decoding', function (): void {
    $loader = new class () implements ImageLoaderInterface {
        public int $inspected = 0;
        public int $loaded = 0;

        public function inspect(string $path): ImageInfo
        {
            $this->inspected++;

            return (new ImageLoader())->inspect($path);
        }

        public function loadFromFile(string $path): GdImage
        {
            $this->loaded++;
            [$width, $height] = Fixtures::size($path);
            $image = Fixtures::canvas($width, $height);
            imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, Fixtures::color($image, 10, 200, 30));

            return $image;
        }
    };
    $puzzle = (new PuzzleGenerator(imageLoader: $loader))->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $piece = $puzzle->getPiece(1, 1)->toGdImage();

    expect([$loader->inspected, $loader->loaded])->toBe([1, 1]);
    expect(Fixtures::rgba($piece, 0, 0))->toBe(['r' => 10, 'g' => 200, 'b' => 30, 'a' => 0]);
});

test('generateFromGd reads the caller image without modifying or destroying it', function (): void {
    $image = Fixtures::coordinateImage(48, 36);
    $before = pixelDigest($image);
    $puzzle = (new PuzzleGenerator(new JigsawStyle()))->generateFromGd($image, 3, 4);
    foreach ($puzzle->getPieces() as $piece) {
        $piece->toGdImage();
    }
    unset($puzzle, $piece);
    gc_collect_cycles();

    expect(imagesx($image))->toBe(48);
    expect(pixelDigest($image))->toBe($before);
});

function pixelDigest(GdImage $image): string
{
    $pixels = '';
    for ($y = 0; $y < imagesy($image); $y++) {
        for ($x = 0; $x < imagesx($image); $x++) {
            $pixels .= pack('N', Fixtures::pixel($image, $x, $y));
        }
    }

    return hash('sha256', $pixels);
}
