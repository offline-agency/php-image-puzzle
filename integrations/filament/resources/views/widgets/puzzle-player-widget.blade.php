<x-filament-widgets::widget>
    <x-filament::section :heading="$heading">
        @if ($definitionId !== null)
            @livewire('image-puzzle-player', [
                'puzzle' => $key,
                'locale' => app()->getLocale(),
                'themeClass' => 'dark',
            ], key('image-puzzle-widget-' . $key . '-' . $definitionId))
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300" role="status">
                @if ($completedMoves !== null)
                    {{ __('image-puzzle-filament::messages.completed', ['moves' => $completedMoves]) }}
                @endif
            </p>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{ __('image-puzzle-filament::messages.unavailable') }}
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
