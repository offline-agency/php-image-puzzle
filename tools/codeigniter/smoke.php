<?php

declare(strict_types=1);

/*
 * Drives the CodeIgniter 4 example over real HTTP (php -S with its router), like a
 * browser: upload a photo, follow the redirect, load the player page, the
 * definition and the player script, save and restore validated progress,
 * and check that bad uploads, foreign states and path tricks are refused.
 * Usage: php tools/codeigniter/smoke.php <base-url> <app>/vendor/autoload.php
 * (see run.sh)
 */

use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameEngine;

$base = rtrim($argv[1] ?? '', '/');
$autoload = $argv[2] ?? '';
if ($base === '' || !is_file($autoload)) {
    fwrite(STDERR, "usage: smoke.php <base-url> <application vendor/autoload.php>\n");
    exit(2);
}
// The application's autoloader: the checks use the installed dist copy.
require $autoload;

final class Smoke
{
    public static int $failures = 0;

    public static function check(string $label, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            echo "ok    {$label}\n";

            return;
        }
        fwrite(STDERR, "FAIL  {$label}" . ($detail === '' ? '' : ": {$detail}") . "\n");
        self::$failures++;
    }
}

/**
 * @param non-empty-string $method
 * @param array<string, mixed>|string|null $body
 * @param list<string> $headers
 *
 * @return array{status: int, body: string, location: string}
 */
function request(string $method, string $url, array|string|null $body = null, array $headers = []): array
{
    $curl = curl_init($url);
    $location = '';
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => static function (CurlHandle $curl, string $header) use (&$location): int {
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            }

            return strlen($header);
        },
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

    return ['status' => $status, 'body' => is_string($response) ? $response : '', 'location' => $location];
}

$work = sys_get_temp_dir() . '/ci4-smoke-' . bin2hex(random_bytes(6));
mkdir($work, 0o700);
try {
    $image = imagecreatetruecolor(320, 240);
    for ($y = 0; $y < 240; $y += 16) {
        imagefilledrectangle($image, 0, $y, 319, $y + 15, (int) imagecolorallocate($image, min(255, $y), min(255, max(0, 200 - intdiv($y, 2))), 90));
    }
    imagejpeg($image, "{$work}/photo.jpg", 90);
    file_put_contents("{$work}/fake.jpg", "<?php echo 'not an image';");

    $form = request('GET', "{$base}/");
    Smoke::check('the upload form renders', $form['status'] === 200 && str_contains($form['body'], 'name="photo"'), (string) $form['status']);

    $upload = request('POST', "{$base}/play", [
        'photo' => new CURLFile("{$work}/photo.jpg", 'image/jpeg', '../../evil name.jpg'),
        'kind' => 'jigsaw',
        'preset' => 'easy',
        'seed' => '4',
    ]);
    $match = preg_match('~/play/(p[0-9a-f]{16})$~', $upload['location'], $m) === 1;
    $alert = preg_match('~role="alert"[^>]*>(.*?)<~s', $upload['body'], $message) === 1 ? trim($message[1]) : '';
    Smoke::check('an upload builds a puzzle and redirects to it', in_array($upload['status'], [302, 303], true) && $match, "{$upload['status']} {$upload['location']} {$alert}");
    if (!isset($m[1])) {
        throw new RuntimeException('the upload did not produce a puzzle; the remaining checks need one');
    }
    $id = $m[1];

    $page = request('GET', "{$base}/play/{$id}");
    $found = preg_match('~<image-puzzle-player id="game" src="([^"]+)"~', $page['body'], $src) === 1;
    Smoke::check('the play page renders the player', $page['status'] === 200 && $found, (string) $page['status']);
    $definitionUrl = html_entity_decode($src[1] ?? '', ENT_QUOTES);
    Smoke::check('the definition URL is on this server', str_starts_with($definitionUrl, $base . '/puzzles/' . $id . '/'), $definitionUrl);

    $definitionResponse = request('GET', $definitionUrl);
    $definition = GameDefinition::fromJson($definitionResponse['body']);
    Smoke::check('the definition is served and valid', $definitionResponse['status'] === 200 && $definition->kind->value === 'jigsaw');
    $script = request('GET', "{$base}/image-puzzle-player.iife.js");
    Smoke::check('the player script is served', $script['status'] === 200 && str_contains($script['body'], 'image-puzzle-player'));
    $pieces = 0;
    foreach ($definition->tiles as $tile) {
        $file = $tile->attributes['image'] ?? null;
        if (is_string($file) && request('GET', $base . '/puzzles/' . $id . '/' . $file)['status'] === 200) {
            $pieces++;
        }
    }
    Smoke::check('every piece image is served', $pieces === count($definition->tiles), "{$pieces} of " . count($definition->tiles));

    Smoke::check('no progress before playing', request('GET', "{$base}/play/{$id}/progress")['status'] === 204);
    $engine = new GameEngine();
    $state = $engine->start($definition);
    $state = $engine->apply($definition, $state, $definition->rules()->hint($definition, $state->board) ?? $definition->rules()->legalActions($definition, $state->board)[0]);
    $saved = request('POST', "{$base}/play/{$id}/progress", (string) json_encode($state->toArray()), ['Content-Type: application/json']);
    $summary = json_decode($saved['body'], true);
    Smoke::check('a valid saved game is stored', $saved['status'] === 200 && is_array($summary) && ($summary['moves'] ?? null) === 1, "{$saved['status']} {$saved['body']}");
    $restored = request('GET', "{$base}/play/{$id}/progress");
    $document = json_decode($restored['body'], true);
    Smoke::check('the saved game is returned on the next visit', $restored['status'] === 200 && is_array($document) && ($document['definitionId'] ?? null) === $definition->id);

    $foreign = $state->toArray();
    $foreign['definitionId'] = 'gd1-' . str_repeat('0', 64);
    Smoke::check('a state for another puzzle is refused', request('POST', "{$base}/play/{$id}/progress", (string) json_encode($foreign), ['Content-Type: application/json'])['status'] === 422);

    $fake = request('POST', "{$base}/play", ['photo' => new CURLFile("{$work}/fake.jpg", 'image/jpeg', 'fake.jpg'), 'kind' => 'swap', 'preset' => 'easy']);
    Smoke::check('a file that is not an image is refused', $fake['status'] === 422 && $fake['location'] === '', (string) $fake['status']);
    $renamed = request('POST', "{$base}/play", ['photo' => new CURLFile("{$work}/photo.jpg", 'image/jpeg', 'photo.php'), 'kind' => 'swap', 'preset' => 'easy']);
    Smoke::check('an image uploaded with a script extension is refused', $renamed['status'] === 422 && $renamed['location'] === '', (string) $renamed['status']);
    $traversal = request('GET', "{$base}/play/..%2F..%2Fapp%2FConfig%2FApp");
    Smoke::check('a path in the puzzle ID is not found', $traversal['status'] === 404, (string) $traversal['status']);
} finally {
    $files = glob("{$work}/*");
    foreach ($files === false ? [] : $files as $file) {
        unlink($file);
    }
    rmdir($work);
}

if (Smoke::$failures > 0) {
    fwrite(STDERR, Smoke::$failures . " CodeIgniter check(s) failed\n");
    exit(1);
}
echo "CodeIgniter example checks passed\n";
