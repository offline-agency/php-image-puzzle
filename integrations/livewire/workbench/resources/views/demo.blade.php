<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Image puzzle — Livewire</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 56rem; margin: 0 auto; padding: 1rem; }
    </style>
</head>
<body>
    <h1>Livewire player</h1>
    <p><a href="/other" wire:navigate>Other page</a></p>
    <p id="status" role="status"></p>
    <section id="swap-section">
        <h2>Swap</h2>
        <livewire:image-puzzle-player puzzle="swap" />
    </section>
    <section id="jigsaw-section">
        <h2>Jigsaw</h2>
        <livewire:image-puzzle-player puzzle="jigsaw" locale="it" />
    </section>
    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('image-puzzle-completed', (event) => {
                document.getElementById('status').textContent = `${event.puzzle} completed in ${event.moves} moves (verified by the server)`;
            });
        });
    </script>
</body>
</html>
