<?php
/**
 * @var list<string> $kinds
 * @var string|null $error
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New puzzle</title>
</head>
<body>
<main>
    <h1>New puzzle</h1>
    <?php if ($error !== null): ?>
        <p role="alert"><?= esc($error) ?></p>
    <?php endif ?>
    <?= form_open_multipart('play') ?>
        <p><label>Photo <input type="file" name="photo" accept="image/png,image/jpeg,image/gif,image/webp" required></label></p>
        <p><label>Mode
            <select name="kind">
                <?php foreach ($kinds as $kind): ?>
                    <option value="<?= esc($kind, 'attr') ?>"><?= esc(ucfirst($kind)) ?></option>
                <?php endforeach ?>
            </select>
        </label></p>
        <p><label>Difficulty
            <select name="preset">
                <option value="easy">Easy</option>
                <option value="medium">Medium</option>
                <option value="hard">Hard</option>
            </select>
        </label></p>
        <p><label>Seed <input type="number" name="seed" min="0" value="1"></label></p>
        <p><button type="submit">Build the puzzle</button></p>
    <?= form_close() ?>
</main>
</body>
</html>
