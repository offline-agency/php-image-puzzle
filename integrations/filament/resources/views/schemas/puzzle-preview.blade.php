@php
    $puzzle = $getPuzzleState();
    $pending = in_array($puzzle['status'], [\ImagePuzzle\Filament\Contracts\PuzzleRecord::STATUS_QUEUED, \ImagePuzzle\Filament\Contracts\PuzzleRecord::STATUS_RUNNING], true);
@endphp

<div
    {{ $getExtraAttributeBag()->class(['ip-filament-preview grid gap-3']) }}
    @if ($pending) wire:poll.2s @endif
    data-status="{{ $puzzle['status'] ?? 'none' }}"
>
    @if ($pending)
        <p class="text-sm text-gray-600 dark:text-gray-300" role="status">
            {{ __('image-puzzle-filament::messages.status.' . $puzzle['status']) }}
        </p>
    @elseif ($puzzle['status'] === \ImagePuzzle\Filament\Contracts\PuzzleRecord::STATUS_FAILED)
        <p class="text-sm text-danger-600 dark:text-danger-400" role="alert">
            {{ __('image-puzzle-filament::messages.status.failed') }}
            {{ $puzzle['error'] }}
        </p>
    @endif

    @if ($puzzle['playable'])
        @livewire('image-puzzle-player', [
            'puzzle' => $puzzle['key'],
            'locale' => $getLocale(),
            'themeClass' => 'dark',
        ], key('image-puzzle-preview-' . $puzzle['key'] . '-' . $puzzle['definitionId']))
    @elseif (! $pending)
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('image-puzzle-filament::messages.not_generated') }}
        </p>
    @endif
</div>
