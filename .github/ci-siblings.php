<?php

declare(strict_types=1);

/**
 * Splice the sibling packages into composer.json as `path` repositories, for CI.
 *
 * Usage (from the package root):
 *     php .github/ci-siblings.php '{"nandan108/invflux-core":"../invflux-core", …}'
 *
 * ## Why this is not composer.local.json
 *
 * The dev tree resolves siblings through `composer.local.json`, which is read by
 * `wikimedia/composer-merge-plugin`. That plugin is a **dev dependency**, so on a fresh CI
 * checkout — no `vendor/` yet — it is not installed when `composer update` resolves. The merge
 * therefore never happens, and it fails *silently*: the path repositories are simply absent, so
 * composer falls through to Packagist and resolves published releases instead of the working
 * tree. A build can go green that way while testing none of the code under development — the
 * false-green this sibling-resolution design exists to reject. Writing directly into
 * composer.json needs no plugin and cannot silently degrade.
 *
 * ## Why the version label matters
 *
 * A `path` repository advertises the checkout as `dev-main`, and `dev-main` does not satisfy a
 * transitive constraint like `^0.15` coming from another sibling. Composer treats a path repo as
 * canonical, so it will not fall back to Packagist for that package either — resolution simply
 * fails. Labelling each checkout with its newest git tag makes those constraints resolvable
 * against the working tree. This is why the dev `composer.local.json` files carry `versions`
 * pins; they are load-bearing, not leftovers. Reading the tag rather than hardcoding keeps the
 * label correct as releases are cut. An untagged package stays `dev-main`, which is fine as long
 * as nothing constrains it by range.
 *
 * ## The tag has to be *fetchable*
 *
 * `git describe` needs the tag to be an ancestor of HEAD, and `actions/checkout` clones with
 * `fetch-depth: 1` by default — one commit, no tags. The label then silently degrades to
 * `dev-main` and resolution fails several steps later with a message that reads like a version
 * conflict between two siblings, pointing nowhere near the checkout that caused it. Every sibling
 * checkout therefore sets `fetch-depth: 0`, and the shallow-clone warning below exists so that if
 * one is ever added without it, the log says so at the point of failure.
 */

if (!isset($argv[1])) {
    fwrite(STDERR, "usage: php .github/ci-siblings.php '{\"vendor/pkg\":\"../dir\", …}'\n");
    exit(2);
}

$file = 'composer.json';
if (!is_file($file)) {
    fwrite(STDERR, "ci-siblings: no composer.json in ".getcwd()."\n");
    exit(2);
}

/** @var array<string, string> $siblings */
$siblings = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$json     = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

$repos = $json['repositories'] ?? [];

foreach ($siblings as $package => $dir) {
    if (!is_dir($dir)) {
        fwrite(STDERR, "ci-siblings: sibling directory '$dir' for $package is missing — was it checked out?\n");
        exit(1);
    }

    $repo = ['type' => 'path', 'url' => $dir, 'options' => ['symlink' => true]];

    $git = fn (string $cmd): string => trim((string) @shell_exec(sprintf('git -C %s %s 2>/dev/null', escapeshellarg($dir), $cmd)));

    $tag = $git('describe --tags --abbrev=0');
    if ('' !== $tag) {
        $repo['options']['versions'] = [$package => ltrim($tag, 'v')];
    } elseif ('true' === $git('rev-parse --is-shallow-repository')) {
        // Untagged and shallow are indistinguishable from here: a depth-1 clone has no tag to
        // find even when the repository is thick with them. Say so rather than labelling the
        // checkout `dev-main` and letting composer fail later with an unrelated-looking message.
        fwrite(STDERR, "::warning::ci-siblings: $dir is a shallow clone, so its release tag is invisible and $package "
            ."will be labelled dev-main. If anything constrains $package by range, resolution will fail. "
            ."Add `fetch-depth: 0` to its checkout step.\n");
    }
    $repos[] = $repo;

    // Override the constraint wherever it actually lives, so a package the host provides at
    // runtime (declared under require-dev, as the add-on does) is honoured too.
    $section = isset($json['require-dev'][$package]) && !isset($json['require'][$package])
        ? 'require-dev'
        : 'require';
    $json[$section][$package] = '*@dev';

    printf("  %-38s <- %s%s\n", $package, $dir, '' !== $tag ? "  (labelled {$tag})" : '  (dev-main, untagged)');
}

$json['repositories']      = $repos;
$json['minimum-stability'] = 'dev';
// Siblings arrive as *@dev path repos regardless; prefer-stable keeps a fresh resolve from also
// dragging in dev versions of unrelated third-party packages that the local lock would have pinned.
$json['prefer-stable'] = true;

file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
