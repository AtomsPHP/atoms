<?php

declare(strict_types=1);

require __DIR__ . '/release.php';

$source = dirname(__DIR__, 2);
$temp = sys_get_temp_dir() . '/atoms-compatibility-' . bin2hex(random_bytes(8));
mkdir($temp . '/packages/laravel', 0700, true);
mkdir($temp . '/packages/database-illuminate', 0700, true);
mkdir($temp . '/packages/cli/resources', 0700, true);
$paths = ['laravel', 'database-illuminate'];
foreach ($paths as $path) {
    copy("{$source}/packages/{$path}/composer.json", "{$temp}/packages/{$path}/composer.json");
}
copy(
    "{$source}/packages/cli/resources/runtime-extensions.json",
    "{$temp}/packages/cli/resources/runtime-extensions.json",
);
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
    foreach ($changedToolchain['laravel']['ci'] as &$ciEntry) {
        unset($ciEntry['php_max']);
    }
    unset($ciEntry);
    $changedToolchain['runtime']['node'] = '24';
    $changedToolchain['runtime']['node_tested'] = ['24', '26'];
    $changedToolchain['runtime']['wrangler'] = '5.2.1';
    $changedToolchain['runtime']['guest_php'] = '9.0';
    $toolchainPage = renderCompatibility($temp, $changedToolchain);
    expect(str_contains($toolchainPage, '| Host PHP | `^9.0` | PHP 9.1 and PHP 9.2 |'), 'Host PHP constraint and tested versions must come from the manifest');
    expect(str_contains($toolchainPage, '| Node.js | `>=24` | Node.js 24 and 26 |'), 'Node constraint and tested versions must come from the manifest');
    expect(str_contains($toolchainPage, '| Wrangler | `5.2.1` | Wrangler 5.2.1 |'), 'Wrangler pin must come from the manifest');
    expect(str_contains($toolchainPage, 'PHP 9.0 interpreter compiled to WebAssembly'), 'Guest interpreter version must come from the manifest');

    $patchFloorNode = $manifest;
    $patchFloorNode['runtime']['node'] = '22.12.0';
    expect(validateManifest($patchFloorNode) === [], 'Exact minimum Node.js versions must pass manifest validation');
    $patchFloorPage = renderCompatibility($temp, $patchFloorNode);
    expect(str_contains($patchFloorPage, '| Node.js | `>=22.12.0` | Node.js 22, 24 and 26 |'), 'Node constraint must keep the exact floor while CI support lists the tested majors');
    $patchFloorNode['runtime']['node_tested'] = ['24', '26'];
    expect(validateManifest($patchFloorNode) !== [], 'Worker CI must test the minimum supported Node major');

    foreach (['^22.12.0', '22.12', ''] as $invalidNode) {
        $invalidManifest = $manifest;
        $invalidManifest['runtime']['node'] = $invalidNode;
        expect(validateManifest($invalidManifest) !== [], "Invalid runtime.node must fail validation: {$invalidNode}");
    }
    foreach (['^4.165.0', '4.165', 'latest'] as $invalidWrangler) {
        $invalidManifest = $manifest;
        $invalidManifest['runtime']['wrangler'] = $invalidWrangler;
        expect(validateManifest($invalidManifest) !== [], "Invalid runtime.wrangler must fail validation: {$invalidWrangler}");
    }

    $expandedPhp = $manifest;
    $expandedPhp['php']['tested'] = ['8.3', '8.4', '8.5', '8.6'];
    expect(validateManifest($expandedPhp) === [], 'Host PHP coverage must be configurable without changing the guest target');
    $expandedPage = renderCompatibility($temp, $expandedPhp);
    expect(str_contains($expandedPage, '| Host PHP | `^8.3` | PHP 8.3, PHP 8.4, PHP 8.5 and PHP 8.6 |'), 'Host table must list all tested PHP versions');
    expect(str_contains($expandedPage, 'PHP 8.3 interpreter compiled to WebAssembly'), 'Expanding host coverage must leave the guest on PHP 8.3');
    $expandedMatrix = compatibilityBlocks($temp, $expandedPhp)['.github/workflows/ci.yml'][2];
    expect(str_contains($expandedMatrix, "php-version: ['8.3', '8.4', '8.5', '8.6']"), 'Every tested host PHP version must run in CI');
    foreach ($expandedPhp['laravel']['ci'] as $entry) {
        expect(str_contains($expandedMatrix, "version: '{$entry['version']}'"), 'Every Laravel CI version must remain in the matrix');
    }
    $profiles = laravelCompatibility($temp, $expandedPhp)['ci'];
    expect(count($profiles) === 3, 'CI must cover Laravel 11, 12 and 13');
    expect($profiles[0]['version'] === '11' && $profiles[0]['php'] === ['8.3', '8.4', '8.5'] && $profiles[0]['bridge'] === false, 'Laravel 11 must cover PHP 8.3–8.5 without the database-illuminate bridge');
    expect($profiles[1]['version'] === '12' && $profiles[1]['php'] === ['8.3', '8.4', '8.5'] && $profiles[1]['bridge'] === true, 'Laravel 12 must retain coverage through PHP 8.5 with the bridge');
    expect($profiles[2]['version'] === '13' && $profiles[2]['php'] === $expandedPhp['php']['tested'] && $profiles[2]['bridge'] === true, 'Laravel 13 must cover every tested host PHP version with the bridge');
    expect(
        str_contains(
            $expandedMatrix,
            "exclude:\n          - php-version: '8.6'\n            laravel: {version: '11', testbench: '^9.0', bridge: false}\n          - php-version: '8.6'\n            laravel: {version: '12', testbench: '^10.0', bridge: true}",
        ),
        'CI must exclude the unsupported Laravel 11/12 on PHP 8.6 combinations',
    );
    expect(str_contains($expandedPage, '`atoms/laravel` supports Laravel 11, 12 and 13, tested in CI across these combinations:'), 'Docs must state that all supported Laravel majors are tested in CI');
    expect(!str_contains($expandedPage, 'no current CI coverage'), 'Docs must not report untested Laravel majors');
    expect(str_contains($expandedPage, '| Laravel 11 | `^9.0` | PHP 8.3, PHP 8.4 and PHP 8.5 |'), 'Docs must list Laravel 11 CI coverage and PHP cap');
    expect(str_contains($expandedPage, '| Laravel 12 | `^10.0` | PHP 8.3, PHP 8.4 and PHP 8.5 |'), 'Docs must respect the Laravel PHP cap');
    expect(str_contains($expandedPage, '| Laravel 13 | `^11.0` | PHP 8.3, PHP 8.4, PHP 8.5 and PHP 8.6 |'), 'Docs must retain PHP 8.6 coverage for Laravel 13');
    expect(str_contains($originalBlocks['docs-site/src/content/docs/getting-started/install.md'][2], 'Supports Laravel 11, 12 and 13 (tested in CI).'), 'Install notice must reflect full CI coverage');
    expect(str_contains($originalBlocks['docs-site/src/content/docs/getting-started/laravel.md'][2], 'Supports Laravel 11, 12 and 13 (tested in CI).'), 'Laravel notice must reflect full CI coverage');

    foreach (['8.6-dev', 8.5, null, '8.2'] as $invalidMax) {
        $invalidManifest = $expandedPhp;
        $invalidManifest['laravel']['ci'][0]['php_max'] = $invalidMax;
        try {
            laravelCompatibility($temp, $invalidManifest);
            throw new RuntimeException('Invalid Laravel PHP cap was accepted');
        } catch (RuntimeException $error) {
            expect(str_contains($error->getMessage(), 'php_max') || str_contains($error->getMessage(), 'no tested host'), 'Invalid Laravel PHP caps must fail validation');
        }
    }
    $uncoveredPhp = $expandedPhp;
    $uncoveredPhp['laravel']['ci'][2]['php_max'] = '8.5';
    try {
        laravelCompatibility($temp, $uncoveredPhp);
        throw new RuntimeException('Uncovered host PHP version was accepted');
    } catch (RuntimeException $error) {
        expect(str_contains($error->getMessage(), 'Every php.tested'), 'Every advertised host version must retain CI coverage');
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
    try {
        laravelCompatibility($temp, $manifest);
        throw new RuntimeException('Uncovered Laravel major in packages/laravel/composer.json was accepted');
    } catch (RuntimeException $error) {
        expect(
            str_contains($error->getMessage(), 'Every Laravel major') && str_contains($error->getMessage(), 'missing: 14'),
            'Adding an uncovered Laravel major must fail validation',
        );
    }
    $expandedLaravel = $manifest;
    $expandedLaravel['laravel']['ci'][] = ['version' => '14', 'testbench' => '^12.0'];
    expect(renderCompatibility($temp, $expandedLaravel) !== $originalPage, 'Adapter constraint change with CI coverage must stale the compatibility page');
    $changedBlocks = compatibilityBlocks($temp, $expandedLaravel);
    expect($changedBlocks['docs-site/src/content/docs/getting-started/install.md'][2] !== $originalBlocks['docs-site/src/content/docs/getting-started/install.md'][2], 'Adapter constraint change must stale the install notice');
    expect($changedBlocks['docs-site/src/content/docs/getting-started/laravel.md'][2] !== $originalBlocks['docs-site/src/content/docs/getting-started/laravel.md'][2], 'Adapter constraint change must stale the Laravel notice');
    expect($changedBlocks['.github/workflows/ci.yml'][2] !== $originalBlocks['.github/workflows/ci.yml'][2], 'Adapter constraint change must stale the CI matrix');
    expect(str_contains($changedBlocks['.github/workflows/ci.yml'][2], "{version: '14', testbench: '^12.0', bridge: false}"), 'Uncovered bridge major must set bridge: false');

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
    try {
        laravelCompatibility($temp, $manifest);
        throw new RuntimeException('Uncovered Illuminate Database major was accepted');
    } catch (RuntimeException $error) {
        expect(
            str_contains($error->getMessage(), 'Every Illuminate Database major') && str_contains($error->getMessage(), 'missing: 14'),
            'Adding an uncovered Illuminate Database major must fail validation',
        );
    }
    $bridge['require']['illuminate/database'] = '^13.0';
    writeJsonAtomically($bridgePath, $bridge);
    expect(renderCompatibility($temp, $manifest) !== $originalPage, 'Bridge constraint change must stale the compatibility page');
    expect(str_contains(compatibilityBlocks($temp, $manifest)['.github/workflows/ci.yml'][2], "{version: '12', testbench: '^10.0', bridge: false}"), 'Dropping a bridge major must update bridge flag in CI matrix');
    copy("{$source}/packages/database-illuminate/composer.json", $bridgePath);

    $reducedCi = $manifest;
    array_shift($reducedCi['laravel']['ci']);
    try {
        laravelCompatibility($temp, $reducedCi);
        throw new RuntimeException('Dropping a Laravel major from laravel.ci was accepted');
    } catch (RuntimeException $error) {
        expect(
            str_contains($error->getMessage(), 'Every Laravel major') && str_contains($error->getMessage(), 'missing: 11'),
            'Removing CI coverage for a declared Laravel major must fail validation',
        );
    }

    $manifest['laravel']['ci'][0]['testbench'] = '^12.0';
    expect(renderCompatibility($temp, $manifest) !== $originalPage, 'CI metadata change must stale the compatibility page');
    expect(compatibilityBlocks($temp, $manifest)['.github/workflows/ci.yml'][2] !== $originalBlocks['.github/workflows/ci.yml'][2], 'CI metadata change must stale the CI matrix');

    $extensionsPath = "{$temp}/packages/cli/resources/runtime-extensions.json";
    expect(str_contains($originalPage, '**Networking (`curl`, `soap`)**'), 'Compatibility page must document curl/soap sandbox limits');
    expect(str_contains($originalPage, '**Sessions (`session`)**'), 'Compatibility page must document session sandbox limits');
    writeJsonAtomically($extensionsPath, ['extensions' => ['example' => '1.0']]);
    expect(str_contains(renderCompatibility($temp, $manifest), '`example`'), 'Compatibility page must list Worker extensions');

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
    $actionRefs = <<<'MD'
- uses: AtomsPHP/atoms/action@v0
- uses: AtomsPHP/atoms/action@v0.10
- uses: AtomsPHP/atoms/action@v0.9.2
- uses: AtomsPHP/atoms/action@v0.10.0-rc.1
- uses: AtomsPHP/atoms/action@0123456789abcdef0123456789abcdef01234567
Use `AtomsPHP/atoms/action@v0.10.0`.
MD;
    expect(
        outdatedActionTags($actionRefs, '0.10.0') === ['v0', 'v0.10', 'v0.9.2', 'v0.10.0-rc.1'],
        'Action references must use the full current release tag',
    );
    fwrite(STDOUT, "Compatibility generation tests passed.\n");
} finally {
    foreach ($paths as $path) {
        unlink("{$temp}/packages/{$path}/composer.json");
        rmdir("{$temp}/packages/{$path}");
    }
    unlink("{$temp}/packages/cli/resources/runtime-extensions.json");
    rmdir("{$temp}/packages/cli/resources");
    rmdir("{$temp}/packages/cli");
    rmdir("{$temp}/packages");
    rmdir($temp);
}
