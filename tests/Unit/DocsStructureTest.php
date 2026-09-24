<?php

declare(strict_types=1);

/*
 * The docs site grades a package `complete` only when every docs folder has an
 * `_index.md` and every page carries title, weight and description frontmatter, and
 * only three files live at the docs root. These hold the layout to that — and every
 * relative link in the docs, README and SECURITY.md to a file that exists.
 */

/**
 * @return list<string>
 */
function docsPages(): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/docs', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with((string) $file, '.md')) {
            $files[] = (string) $file;
        }
    }

    sort($files);

    return $files;
}

it('keeps only index, quickstart and requirements at the docs root', function (): void {
    $root = array_map('basename', glob(dirname(__DIR__, 2).'/docs/*.md') ?: []);
    sort($root);

    expect($root)->toBe(['index.md', 'quickstart.md', 'requirements.md']);
});

it('gives every docs folder an _index.md', function (): void {
    $missing = [];

    foreach (glob(dirname(__DIR__, 2).'/docs/*', GLOB_ONLYDIR) ?: [] as $folder) {
        if (! is_file($folder.'/_index.md')) {
            $missing[] = basename($folder);
        }
    }

    expect($missing)->toBe([]);
});

it('gives every page a title, a weight and a description', function (): void {
    $incomplete = [];

    foreach (docsPages() as $path) {
        $source = (string) file_get_contents($path);

        if (preg_match('/\A---\n(.*?)\n---\n/s', $source, $match) !== 1) {
            $incomplete[] = basename(dirname($path)).'/'.basename($path).' (no frontmatter)';

            continue;
        }

        foreach (['title', 'weight', 'description'] as $field) {
            if (preg_match('/^'.$field.':\s*\S/m', $match[1]) !== 1) {
                $incomplete[] = basename(dirname($path)).'/'.basename($path).' (no '.$field.')';
            }
        }
    }

    expect($incomplete)->toBe([]);
});

it('has no dangling relative link in the docs, README or SECURITY.md', function (): void {
    $root = dirname(__DIR__, 2);
    $dangling = [];

    foreach ([...docsPages(), $root.'/README.md', $root.'/SECURITY.md'] as $path) {
        preg_match_all('/\]\(([^)\s]+)\)/', (string) file_get_contents($path), $links);

        foreach ($links[1] as $link) {
            if (preg_match('#^(https?:|mailto:|\#)#', $link) === 1) {
                continue;
            }

            $target = dirname($path).'/'.explode('#', $link)[0];

            if (! file_exists($target)) {
                $dangling[] = str_replace($root.'/', '', $path).' -> '.$link;
            }
        }
    }

    expect($dangling)->toBe([]);
});
