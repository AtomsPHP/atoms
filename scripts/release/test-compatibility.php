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
    $changedToolchain = $manifest;
    $changedToolchain['php']['constraint'] = '^9.0';
    $changedToolchain['php']['tested'] = ['9.1', '9.2'];
    $changedToolchain['runtime']['node'] = '24';
    $changedToolchain['runtime']['wrangler'] = '5.2.1';
    $changedToolchain['runtime']['guest_php'] = '9.0';
    $toolchainPage = renderCompatibility($temp, $changedToolchain);
    expect(str_contains($toolchainPage, '| Host PHP | `^9.0` | PHP 9.1 and PHP 9.2 |'), 'Host PHP constraint and tested versions must come from the manifest');
    expect(str_contains($toolchainPage, '| Node.js | `>=24` | Node.js 24 |'), 'Node constraint and version must come from the manifest');
    expect(str_contains($toolchainPage, '| Wrangler | `5.2.1` | Wrangler 5.2.1 |'), 'Wrangler pin must come from the manifest');
    expect(str_contains($toolchainPage, 'PHP 9.0 interpreter compiled to WebAssembly'), 'Guest interpreter version must come from the manifest');

    $expandedPhp = $manifest;
    $expandedPhp['php']['tested'] = ['8.3', '8.4', '8.5', '8.6'];
    expect(validateManifest($expandedPhp) === [], 'Host PHP coverage must be configurable without changing the guest target');
    $expandedPage = renderCompatibility($temp, $expandedPhp);
    expect(str_contains($expandedPage, '| Host PHP | `^8.3` | PHP 8.3, PHP 8.4, PHP 8.5 and PHP 8.6 |'), 'Host table must list all tested PHP versions');
    expect(str_contains($expandedPage, 'PHP 8.3 interpreter compiled to WebAssembly'), 'Expanding host coverage must leave the guest on PHP 8.3');
    $expandedMatrix = compatibilityBlocks($temp, $expandedPhp)['.github/workflows/ci.yml'][2];
    expect(str_contains($expandedMatrix, "php-version: ['8.3', '8.4', '8.5', '8.6']"), 'Every tested host PHP version must run in CI');
    foreach ($expandedPhp['laravel']['ci'] as $entry) {
        expect(str_contains($expandedMatrix, "version: '{$entry['version']}'"), 'Every Laravel CI version must retain the full host PHP matrix');
    }

    foreach ([
        ['tested' => []],
        ['tested' => ['8.3', '8.3']],
        ['tested' => ['8.3', '8.6-dev']],
        ['tested' => ['8.3', 8.6]],
        ['tested' => '8.3'],
        ['tested' => ['host' => '8.3']],
        ['tested' => ['8.4', '8.5', '8.6']],
    ] as $invalidPhp) {
        $invalidManifest = $expandedPhp;
        $invalidManifest['php'] = array_replace($expandedPhp['php'], $invalidPhp);
        expect(validateManifest($invalidManifest) !== [], 'Invalid host PHP metadata must fail release validation: ' . json_encode($invalidPhp));
    }
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
