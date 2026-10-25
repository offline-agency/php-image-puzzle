<?php
/**
 * @var string $id
 * @var string $kind
 * @var string $definitionUrl
 */
$progressUrl = site_url('play/' . $id . '/progress');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Play</title>
    <script src="<?= esc(base_url('image-puzzle-player.iife.js'), 'attr') ?>"></script>
</head>
<body>
<main>
    <h1><?= esc(ucfirst($kind)) ?> puzzle</h1>
    <image-puzzle-player id="game" src="<?= esc($definitionUrl, 'attr') ?>"></image-puzzle-player>
    <p><a href="<?= esc(site_url('/'), 'attr') ?>">Build another puzzle</a></p>
</main>
<script>
    const game = document.getElementById('game');
    const progressUrl = <?= json_encode($progressUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>;
    const csrf = <?= json_encode([csrf_header() => csrf_hash()], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    fetch(progressUrl)
        .then((response) => (response.status === 200 ? response.json() : null))
        .then((state) => { if (state) game.state = state; });
    let timer;
    game.addEventListener('puzzle-state-change', () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            fetch(progressUrl, {
                method: 'POST',
                headers: { 'content-type': 'application/json', ...csrf },
                body: JSON.stringify(game.exportState()),
            });
        }, 500);
    });
</script>
</body>
</html>
