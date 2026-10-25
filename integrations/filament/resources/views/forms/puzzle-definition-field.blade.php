@php
    $id = $getId();
    $statePath = $getStatePath();
    $imageUrl = $getImageUrl();
    $isDisabled = $isDisabled();
@endphp

@assets
    <script type="module" src="{{ route('image-puzzle.script', ['v' => \ImagePuzzle\Livewire\Puzzles::scriptVersion()]) }}"></script>
@endassets

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            state: $wire.$entangle(@js($statePath)),
            init() {
                const element = this.$refs.configurator;
                const props = this.$refs.props;
                const same = (a, b) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null);
                const applyProps = () => {
                    element.src = props.dataset.src || null;
                    element.disabled = props.dataset.disabled === 'true';
                };
                customElements.whenDefined('image-puzzle-configurator').then(() => {
                    applyProps();
                    if (this.state === null || this.state === undefined) {
                        // Save what the author sees: the defaults become the state.
                        this.state = element.draft;
                    } else {
                        element.value = this.state;
                    }
                    element.addEventListener('configurator-change', (event) => {
                        if (!same(this.state, event.detail.draft)) this.state = event.detail.draft;
                    });
                    this.$watch('state', (value) => {
                        if (!same(value, element.draft)) element.value = value;
                    });
                    // The server re-renders the props carrier; the element itself is never morphed.
                    new MutationObserver(applyProps).observe(props, { attributes: true });
                });
            },
        }"
        {{ $getExtraAttributeBag()->class(['ip-filament-configurator']) }}
    >
        <span
            hidden
            x-ref="props"
            data-src="{{ $imageUrl }}"
            data-disabled="{{ $isDisabled ? 'true' : 'false' }}"
        ></span>
        <div wire:ignore>
            <image-puzzle-configurator
                x-ref="configurator"
                id="{{ $id }}"
                locale="{{ $getLocale() }}"
                theme-class="dark"
                @if ($getKinds() !== []) kinds="{{ implode(' ', $getKinds()) }}" @endif
                @if ($isDisabled) disabled @endif
                @if ($imageUrl !== null) src="{{ $imageUrl }}" @endif
            ></image-puzzle-configurator>
        </div>
    </div>
</x-dynamic-component>
