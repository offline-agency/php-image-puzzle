<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use ImagePuzzle\Batch\BatchItem;
use ImagePuzzle\Game\BuildConfiguration;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Livewire\Jobs\BuildPuzzle;
use ImagePuzzle\Livewire\Jobs\BuildPuzzleFailed;
use ImagePuzzle\Livewire\Jobs\PuzzleBuilt;
use ImagePuzzle\Livewire\Tests\TestCase;

function jobRoot(): string
{
    return sys_get_temp_dir() . '/image-puzzle-livewire-jobs-' . getmypid();
}

function jobItem(string $id, ?string $source = null): BatchItem
{
    $root = jobRoot();

    return new BatchItem($id, $source ?? dirname(TestCase::puzzle('swap')->definitionPath()) . '/source.png', $root . '/' . $id . '-' . bin2hex(random_bytes(4)), new BuildConfiguration(GameKind::Memory, 'easy', [], 3));
}

afterEach(function (): void {
    (new Illuminate\Filesystem\Filesystem())->deleteDirectory(jobRoot());
});

it('builds an item when dispatched and reports it', function (): void {
    Event::fake([PuzzleBuilt::class]);
    $item = jobItem('queued');
    BuildPuzzle::dispatchSync($item);
    expect(is_file($item->output . '/definition.json'))->toBeTrue();
    Event::assertDispatched(PuzzleBuilt::class, static fn (PuzzleBuilt $event): bool => $event->item->id === 'queued' && $event->result->definitionId !== null);
});

it('survives queue serialisation', function (): void {
    $job = unserialize(serialize(new BuildPuzzle(jobItem('serialised'))));
    expect($job)->toBeInstanceOf(BuildPuzzle::class);
    if ($job instanceof BuildPuzzle) {
        expect($job->item->config->kind)->toBe(GameKind::Memory);
    }
});

it('fails the job and leaves no output when the item fails', function (): void {
    Event::fake([PuzzleBuilt::class]);
    $item = jobItem('broken', '/nonexistent/photo.png');
    expect(fn () => BuildPuzzle::dispatchSync($item))->toThrow(BuildPuzzleFailed::class, 'Puzzle broken failed');
    expect(file_exists($item->output))->toBeFalse();
    Event::assertNotDispatched(PuzzleBuilt::class);
});

it('skips the item when its Bus batch or cancel file is cancelled', function (): void {
    Event::fake([PuzzleBuilt::class]);
    $item = jobItem('batched');
    [$job, $batch] = (new BuildPuzzle($item))->withFakeBatch();
    $batch->cancel();
    $job->handle(app(ImagePuzzle\Game\DefinitionBuilder::class));
    expect(file_exists($item->output))->toBeFalse();

    $flag = sys_get_temp_dir() . '/image-puzzle-cancel-' . bin2hex(random_bytes(4));
    touch($flag);
    $other = jobItem('flagged');
    BuildPuzzle::dispatchSync($other, $flag);
    unlink($flag);
    expect(file_exists($other->output))->toBeFalse();
    Event::assertNotDispatched(PuzzleBuilt::class);
});
