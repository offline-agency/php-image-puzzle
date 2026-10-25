<div class="image-puzzle-livewire" data-puzzle="{{ $puzzle }}">
    @assets
        <script type="module" src="{{ $scriptUrl }}"></script>
    @endassets

    {{-- The custom element owns its DOM; Livewire morphs never touch it. A new
         wire:key (puzzle change or server reset) replaces it with a fresh game. --}}
    <div wire:ignore wire:key="image-puzzle-{{ $definitionId }}-{{ $generation }}">
        <image-puzzle-player
            src="{{ $definitionUrl }}"
            @if ($locale !== null) locale="{{ $locale }}" @endif
            @if (! $controls) no-controls @endif
        ></image-puzzle-player>
    </div>
</div>

@script
<script>
    const root = $wire.$el;
    const readOnly = @js($readOnly);
    const debounceMs = @js($debounceMs);
    let pending = null;
    let timer = null;
    let restoring = false;

    function flush() {
        if (timer !== null) clearTimeout(timer);
        timer = null;
        if (pending === null || readOnly) return;
        const state = pending;
        pending = null;
        $wire.save(state).catch(() => {
            // Keep the newest unsent state for the next attempt.
            pending ??= state;
        });
    }

    root.addEventListener('puzzle-ready', async (event) => {
        const player = event.target;
        if (player.dataset.restored === 'true') return;
        player.dataset.restored = 'true';
        const saved = await $wire.savedState();
        if (saved === null || !player.isConnected) return;
        restoring = true;
        try {
            player.importState(saved);
        } catch {
            // A stale or invalid save leaves the fresh game untouched.
        } finally {
            restoring = false;
        }
    });

    root.addEventListener('puzzle-state-change', (event) => {
        const reason = event.detail.reason;
        if (readOnly || restoring || reason === 'load' || reason === 'start' || reason === 'pause') return;
        pending = event.detail.state;
        if (timer !== null) clearTimeout(timer);
        timer = setTimeout(flush, debounceMs);
    });

    root.addEventListener('puzzle-complete', flush);

    // Save before the page is hidden or left through wire:navigate; listeners
    // remove themselves once this component is gone.
    const cleanup = () => {
        document.removeEventListener('visibilitychange', onVisibility);
        document.removeEventListener('livewire:navigating', onNavigate);
    };
    const onVisibility = () => {
        if (!root.isConnected) return cleanup();
        if (document.visibilityState === 'hidden') flush();
    };
    const onNavigate = () => {
        flush();
        if (!root.isConnected) cleanup();
    };
    document.addEventListener('visibilitychange', onVisibility);
    document.addEventListener('livewire:navigating', onNavigate);
</script>
@endscript
