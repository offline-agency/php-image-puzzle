<?php

declare(strict_types=1);

/*
 * Links in the maintained documentation resolve: relative Markdown links
 * point at files or directories in the repository, and links to this
 * repository on GitHub (blob/HEAD, tree/HEAD) point at paths that exist.
 * Anchors and external sites are not fetched.
 */

/**
 * @return list<string>
 */
function linkedDocuments(): array
{
    $repo = dirname(__DIR__, 2);
    $files = ['README.md', 'CONTRIBUTING.md', 'SECURITY.md', 'CHANGELOG.md', 'AGENTS.md', 'llms.txt'];
    foreach (['/docs/*.md', '/docs/*/*.md', '/integrations/*/README.md', '/packages/*/README.md', '/examples/*/README.md'] as $pattern) {
        $found = glob($repo . $pattern);
        foreach ($found === false ? [] : $found as $path) {
            $files[] = substr($path, strlen($repo) + 1);
        }
    }

    return $files;
}

it('resolves every repository link', function (string $document): void {
    $repo = dirname(__DIR__, 2);
    $text = (string) file_get_contents($repo . '/' . $document);
    // Code spans and blocks may show link syntax without being links.
    $text = (string) preg_replace(['/^```.*?^```/sm', '/`[^`\n]*`/'], '', $text);
    preg_match_all('/\]\(([^)\s]+)(?:\s+"[^"]*")?\)|^\[[^\]]+\]:\s*(\S+)/m', $text, $matches, PREG_SET_ORDER);

    $broken = [];
    foreach ($matches as $match) {
        $target = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');
        if (preg_match('~^https://github\.com/offline-agency/php-image-puzzle/(?:blob|tree)/HEAD/([^#?]+)~', $target, $own) === 1) {
            $path = $repo . '/' . rawurldecode($own[1]);
        } elseif ($target === '' || preg_match('~^([a-z][a-z0-9+.-]*:|#)~i', $target) === 1) {
            continue;
        } else {
            $path = dirname($repo . '/' . $document) . '/' . rawurldecode((string) preg_replace('/[#?].*$/', '', $target));
        }
        if (!file_exists($path)) {
            $broken[] = $target;
        }
    }

    expect($broken)->toBe([]);
})->with(linkedDocuments());
