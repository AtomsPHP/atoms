<?php

declare(strict_types=1);

require __DIR__ . '/release.php';

$source = dirname(__DIR__, 2);
$temp = sys_get_temp_dir() . '/atoms-compatibility-' . bin2hex(random_bytes(8));
mkdir($temp . '/packages/laravel', 0700, true);
mkdir($temp . '/packages/database-illuminate', 0700, true);
$paths = ['laravel', 'database-illuminate'];
foreach ($paths as $path) {
    copy("{$source}/packages/{$path}/composer.json", "{$temp}/packages/{$path}/composer.json");
}
$manifest = readJson("{$source}/release/manifest.json");

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $originalPage = renderCompatibility($temp, $manifest);
    $originalBlocks = compatibilityBlocks($temp, $manifest);
    $adapterPath = "{$temp}/packages/laravel/composer.json";
    $adapter = readJson($adapterPath);
    foreach ($adapter['require'] as $name => $constraint) {
        if (str_starts_with($name, 'illuminate/')) {
            $adapter['require'][$name] = $constraint . ' || ^14.0';
        }
    }
    writeJsonAtomically($adapterPath, $adapter);
    expect(renderCompatibility($temp, $manifest) !== $originalPage, 'Adapter constraint change must stale the compatibility page');
    $changedBlocks = compatibilityBlocks($temp, $manifest);
    expect($changedBlocks['docs-site/src/content/docs/getting-started/install.md'][2] !== $originalBlocks['docs-site/src/content/docs/getting-started/install.md'][2], 'Adapter constraint change must stale the install notice');
    expect($changedBlocks['docs-site/src/content/docs/getting-started/laravel.md'][2] !== $originalBlocks['docs-site/src/content/docs/getting-started/laravel.md'][2], 'Adapter constraint change must stale the Laravel notice');

    $adapter['require']['illuminate/bus'] = '^11.0';
    writeJsonAtomically($adapterPath, $adapter);
    try {
        laravelCompatibility($temp, $manifest);
        throw new RuntimeException('Divergent adapter constraints were accepted');
    } catch (RuntimeException $error) {
        expect(str_contains($error->getMessage(), 'constraints disagree'), 'Expected divergent constraints to fail');
    }
    $adapter['require']['illuminate/bus'] = '>=11';
    writeJsonAtomically($adapterPath, $adapter);
    try {
        laravelCompatibility($temp, $manifest);
        throw new RuntimeException('Unsupported constraint was accepted');
    } catch (RuntimeException $error) {
        expect(str_contains($error->getMessage(), 'Unsupported Illuminate constraint'), 'Expected unsupported constraint shape to fail');
    }
    copy("{$source}/packages/laravel/composer.json", $adapterPath);

    $bridgePath = "{$temp}/packages/database-illuminate/composer.json";
    $bridge = readJson($bridgePath);
    $bridge['require']['illuminate/database'] .= ' || ^14.0';
    writeJsonAtomically($bridgePath, $bridge);
    expect(renderCompatibility($temp, $manifest) !== $originalPage, 'Bridge constraint change must stale the compatibility page');
    copy("{$source}/packages/database-illuminate/composer.json", $bridgePath);

    $reducedCi = $manifest;
    array_shift($reducedCi['laravel']['ci']);
    $reducedBlocks = compatibilityBlocks($temp, $reducedCi);
    expect($reducedBlocks['docs-site/src/content/docs/getting-started/install.md'][2] !== $originalBlocks['docs-site/src/content/docs/getting-started/install.md'][2], 'CI version change must stale the install notice');
    expect($reducedBlocks['docs-site/src/content/docs/getting-started/laravel.md'][2] !== $originalBlocks['docs-site/src/content/docs/getting-started/laravel.md'][2], 'CI version change must stale the Laravel notice');
    expect($reducedBlocks['.github/workflows/ci.yml'][2] !== $originalBlocks['.github/workflows/ci.yml'][2], 'CI version change must stale the matrix');
    expect(renderCompatibility($temp, $reducedCi) !== $originalPage, 'CI version change must stale the compatibility page');

    $manifest['laravel']['ci'][0]['testbench'] = '^12.0';
    expect(renderCompatibility($temp, $manifest) !== $originalPage, 'CI metadata change must stale the compatibility page');
    expect(compatibilityBlocks($temp, $manifest)['.github/workflows/ci.yml'][2] !== $originalBlocks['.github/workflows/ci.yml'][2], 'CI metadata change must stale the CI matrix');

    $block = replaceTaggedBlock("before\nSTART\nold\nEND\nafter", 'START', 'END', 'new');
    expect($block === "before\nSTART\nnew\nEND\nafter", 'Block replacement must preserve surrounding content');
    try {
        replaceTaggedBlock('START START END', 'START', 'END', 'new');
        throw new RuntimeException('Duplicate block markers were accepted');
    } catch (RuntimeException $error) {
        expect(str_contains($error->getMessage(), 'one pair'), 'Expected duplicate markers to fail');
    }
    foreach (['START only', 'END before START'] as $invalidBlock) {
        try {
            replaceTaggedBlock($invalidBlock, 'START', 'END', 'new');
            throw new RuntimeException('Missing or reversed markers were accepted');
        } catch (RuntimeException $error) {
            expect(str_contains($error->getMessage(), 'markers'), 'Expected invalid markers to fail');
        }
    }
    fwrite(STDOUT, "Compatibility generation tests passed.\n");
} finally {
    foreach ($paths as $path) {
        unlink("{$temp}/packages/{$path}/composer.json");
        rmdir("{$temp}/packages/{$path}");
    }
    rmdir("{$temp}/packages");
    rmdir($temp);
}
