<?php

/**
 * Merge the user's volume config/composer.local.json on top of the build-time
 * baked composer.local.json, writing the union to the output path.
 *
 * The baked list (every bundled extension/skin with Composer dependencies) is
 * authoritative: a stale or hand-edited volume copy can ADD entries but can
 * never DROP a bundled one. This prevents bundled libraries (ruflin/elastica,
 * SemanticMediaWiki's packages, ...) from vanishing from vendor/ when the
 * volume copy predates the running image.
 *
 * Usage: php merge-composer-local.php <baked> <user> <output>
 *
 *   merge-plugin.include  union of both arrays, baked first, de-duplicated
 *   require               baked then user (user wins on identical package keys)
 *   repositories          baked then user (user wins on identical keys)
 */

function loadComposerJson($path) {
    if ($path === null || !is_file($path)) {
        return [];
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

$bakedPath = $argv[1] ?? null;
$userPath  = $argv[2] ?? null;
$outPath   = $argv[3] ?? null;

if ($outPath === null) {
    fwrite(STDERR, "usage: merge-composer-local.php <baked> <user> <output>\n");
    exit(1);
}

$baked = loadComposerJson($bakedPath);
$user  = loadComposerJson($userPath);

// Union of merge-plugin includes, baked entries first, de-duplicated.
$bakedInclude = $baked['extra']['merge-plugin']['include'] ?? [];
$userInclude  = $user['extra']['merge-plugin']['include'] ?? [];
$include = array_values(array_unique(array_merge($bakedInclude, $userInclude)));

$merged = [
    'extra' => [
        'merge-plugin' => [
            'include' => $include,
        ],
    ],
];

// require is an object keyed by package name; user overrides baked on clash.
$require = array_merge($baked['require'] ?? [], $user['require'] ?? []);
if ($require) {
    $merged['require'] = $require;
}

// repositories may be a list or an object; array_merge appends lists and
// lets the user override baked entries that share a string key.
$repositories = array_merge($baked['repositories'] ?? [], $user['repositories'] ?? []);
if ($repositories) {
    $merged['repositories'] = $repositories;
}

file_put_contents(
    $outPath,
    json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);
