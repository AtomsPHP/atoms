#!/usr/bin/env php
<?php

declare(strict_types=1);

const PACKAGE_NAMES = [
    'atoms/core',
    'atoms/client',
    'atoms/laravel',
    'atoms/symfony',
    'atoms/testing',
    'atoms/phpstan-rules',
    'atoms/cli',
    'atoms/database-illuminate',
];

$root = dirname(__DIR__, 2);
$command = $argv[1] ?? 'help';

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        match ($command) {
            'check' => checkRelease($root),
            'get' => getManifestValue($root, $argv[2] ?? ''),
            'generate' => generateReleaseArtifacts($root),
            'set' => setReleaseVersion($root, $argv[2] ?? '', $argv[3] ?? 'candidate'),
            'status' => setReleaseStatus($root, $argv[2] ?? ''),
            'split' => splitPackage($root, $argv[2] ?? '', $argv[3] ?? ''),
            'split-all' => splitAll($root, $argv[2] ?? ''),
            'validate-splits' => validateSplits($root),
            'help', '--help', '-h' => usage(),
            default => throw new InvalidArgumentException("Unknown command: {$command}"),
        };
    } catch (Throwable $error) {
        fwrite(STDERR, "release: {$error->getMessage()}\n");
        exit(1);
    }
}
/** @return never */
function usage(): never
{
    fwrite(STDOUT, <<<'TEXT'
Atoms coordinated-release tool

Usage:
  php scripts/release/release.php check
  php scripts/release/release.php get version|status|runtime.package|runtime.version
  php scripts/release/release.php generate
  php scripts/release/release.php set <semver> [candidate|ready]
  php scripts/release/release.php status candidate|ready
  php scripts/release/release.php split <package-slug> <empty-destination>
  php scripts/release/release.php split-all <empty-destination>
  php scripts/release/release.php validate-splits

Package slugs: core, client, laravel, symfony, testing, phpstan-rules, cli, database-illuminate

TEXT);
    exit(0);
}

function checkRelease(string $root): void
{
    $manifest = readJson("{$root}/release/manifest.json");
    $errors = validateManifest($manifest);
    $version = stringValue($manifest, 'version');
    $runtime = arrayValue($manifest, 'runtime');
    $php = arrayValue($manifest, 'php');
    $core = arrayValue($manifest, 'core');
    $composer = arrayValue($manifest, 'composer');
    $internalConstraint = composerConstraint($version);

    if (($runtime['version'] ?? null) !== $version) {
        $errors[] = 'runtime.version must match the coordinated release version';
    }
    if (($core['supported'] ?? null) !== $internalConstraint) {
        $errors[] = "core.supported must be {$internalConstraint} for {$version}";
    }
    if (($php['constraint'] ?? null) !== '^8.3') {
        $errors[] = 'php.constraint must remain ^8.3: the frozen core API targets PHP 8.3';
    }

    $listedPackages = $composer['packages'] ?? null;
    if ($listedPackages !== PACKAGE_NAMES) {
        $errors[] = 'composer.packages must list the eight packages once, in release order';
    }

    // Same rule as the per-package cross-requires below: the root
    // composer.json resolves the path repositories fresh in CI, so a stale
    // atoms/* constraint there makes the whole tree uninstallable.
    $rootComposer = readJson("{$root}/composer.json");
    foreach (['require', 'require-dev'] as $section) {
        foreach (($rootComposer[$section] ?? []) as $dependency => $rootConstraint) {
            if (in_array($dependency, PACKAGE_NAMES, true) && $rootConstraint !== $internalConstraint) {
                $errors[] = "the root composer.json requires {$dependency} at {$rootConstraint}; expected {$internalConstraint}";
            }
        }
    }

    foreach (PACKAGE_NAMES as $packageName) {
        $slug = packageSlug($packageName);
        $packageDir = "{$root}/packages/{$slug}";
        $package = readJson("{$packageDir}/composer.json");

        if (($package['name'] ?? null) !== $packageName) {
            $errors[] = "packages/{$slug}/composer.json has the wrong package name";
        }
        if (($package['version'] ?? null) !== $version) {
            $errors[] = "{$packageName} version must be {$version}";
        }
        if (($package['license'] ?? null) !== 'MIT') {
            $errors[] = "{$packageName} must declare the MIT license";
        }
        if (($package['homepage'] ?? null) !== 'https://atomsphp.dev') {
            $errors[] = "{$packageName} homepage must be https://atomsphp.dev";
        }

        $support = is_array($package['support'] ?? null) ? $package['support'] : [];
        foreach ([
            'docs' => 'https://docs.atomsphp.dev',
            'issues' => 'https://github.com/AtomsPHP/atoms/issues',
            'source' => 'https://github.com/AtomsPHP/atoms',
        ] as $key => $expected) {
            if (($support[$key] ?? null) !== $expected) {
                $errors[] = "{$packageName} support.{$key} must be {$expected}";
            }
        }

        foreach (['require', 'require-dev'] as $section) {
            foreach (($package[$section] ?? []) as $dependency => $constraint) {
                if (in_array($dependency, PACKAGE_NAMES, true) && $constraint !== $internalConstraint) {
                    $errors[] = "{$packageName} requires {$dependency} at {$constraint}; expected {$internalConstraint}";
                }
            }
        }

        foreach (['README.md', 'LICENSE'] as $requiredFile) {
            if (!is_file("{$packageDir}/{$requiredFile}")) {
                $errors[] = "{$packageName} is missing {$requiredFile}";
            }
        }

        $readme = is_file("{$packageDir}/README.md")
            ? (string) file_get_contents("{$packageDir}/README.md")
            : '';
        if (!str_contains($readme, 'read-only distribution mirror')) {
            $errors[] = "{$packageName} README must identify its mirror as read-only";
        }
    }

    $licenses = array_map(
        static fn (string $package): string => hash_file('sha256', "{$root}/packages/" . packageSlug($package) . '/LICENSE') ?: '',
        PACKAGE_NAMES,
    );
    if (count(array_unique($licenses)) !== 1) {
        $errors[] = 'all package-level MIT LICENSE files must be byte-identical';
    }

    $actionStamp = "{$root}/action/runtime-version";
    if (!is_file($actionStamp) || trim((string) file_get_contents($actionStamp)) !== ($runtime['version'] ?? '')) {
        $errors[] = 'action/runtime-version is missing or stale';
    }

    $cliStamp = "{$root}/packages/cli/src/Release/RuntimeVersion.php";
    $cliContents = is_file($cliStamp) ? (string) file_get_contents($cliStamp) : '';
    if ($cliContents !== renderRuntimeVersion($manifest)) {
        $errors[] = 'packages/cli/src/Release/RuntimeVersion.php is missing or stale';
    }

    $runtimePackagePath = "{$root}/cloudflare/worker/package.json";
    if (is_file($runtimePackagePath)) {
        $runtimePackage = readJson($runtimePackagePath);
        if (($runtimePackage['devDependencies']['wrangler'] ?? null) !== ($runtime['wrangler'] ?? null)) {
            $errors[] = 'cloudflare/worker/package.json Wrangler pin does not match runtime.wrangler';
        }
    }

    $supportedCoreStamp = "{$root}/cloudflare/worker/release/supported-core";
    if (!is_file($supportedCoreStamp)
        || trim((string) file_get_contents($supportedCoreStamp)) !== ($core['supported'] ?? '')) {
        $errors[] = 'cloudflare/worker/release/supported-core is missing or stale';
    }

    $changelog = (string) file_get_contents("{$root}/CHANGELOG.md");
    if (!str_contains($changelog, "## [{$version}]")) {
        $errors[] = "CHANGELOG.md has no {$version} entry";
    }

    $compatibilityPath = "{$root}/docs-site/src/content/docs/reference/compatibility.md";
    if (!is_file($compatibilityPath)
        || (string) file_get_contents($compatibilityPath) !== renderCompatibility($root, $manifest)) {
        $errors[] = 'the generated compatibility page is missing or stale; run the release generator';
    }

    foreach (compatibilityBlocks($root, $manifest) as $path => [$start, $end, $body]) {
        $contents = (string) file_get_contents("{$root}/{$path}");
        if ($contents !== replaceTaggedBlock($contents, $start, $end, $body)) {
            $errors[] = "{$path} has a stale generated compatibility block; run the release generator";
        }
    }

    $errors = array_merge(
        $errors,
        validateCurrentReleaseReferences($root, $version, $internalConstraint),
    );

    if ($errors !== []) {
        throw new RuntimeException("release metadata is inconsistent:\n- " . implode("\n- ", $errors));
    }

    fwrite(STDOUT, "Release {$version} ({$manifest['status']}) is internally consistent.\n");
}

/** @return list<string> */
function validateCurrentReleaseReferences(string $root, string $version, string $constraint): array
{
    $relativePaths = [
        'README.md',
        'action/README.md',
        'docs/cloudflare-toolchain.md',
        'examples/laravel/README.md',
        'examples/laravel/composer.json',
        'examples/laravel/.github/workflows/deploy-atoms.yml',
        'examples/plain-php/README.md',
        'examples/plain-php/composer.json',
    ];
    foreach (PACKAGE_NAMES as $packageName) {
        $relativePaths[] = 'packages/' . packageSlug($packageName) . '/README.md';
    }
    // The published docs quote install commands, the runtime package and the
    // Action tag as much as the READMEs do, so they drift the same way. Walk
    // the whole content tree rather than a hand-kept list: a page added after
    // this release still gets checked.
    $relativePaths = array_merge($relativePaths, markdownFilesUnder($root, 'docs-site/src/content/docs'));
    $errors = [];

    foreach ($relativePaths as $relativePath) {
        $contents = (string) file_get_contents("{$root}/{$relativePath}");

        preg_match_all('/@atomsphp\/runtime-cloudflare@(\d+\.\d+\.\d+)/', $contents, $runtimeMatches);
        foreach ($runtimeMatches[1] as $referencedVersion) {
            if ($referencedVersion !== $version) {
                $errors[] = "{$relativePath} references runtime {$referencedVersion}; expected {$version}";
            }
        }

        preg_match_all('/AtomsPHP\/atoms\/action@v(\d+\.\d+\.\d+)/', $contents, $actionMatches);
        foreach ($actionMatches[1] as $referencedVersion) {
            if ($referencedVersion !== $version) {
                $errors[] = "{$relativePath} references Action {$referencedVersion}; expected {$version}";
            }
        }

        // A pinned link into the repository at a release tag drifts exactly like
        // the Action reference above, and is not covered by it: the docs link to
        // `blob/v<version>/action/README.md`, not `action@v<version>`.
        // The whole tag up to the next path separator, not a version-shaped
        // prefix of it: `blob/v0.6.0-rc.1/` and `blob/v0.5.0notes/` would both
        // otherwise report the release they merely start with, and pass.
        preg_match_all('/AtomsPHP\/atoms\/blob\/v([^\/\s)"\']+)\//', $contents, $blobMatches);
        foreach ($blobMatches[1] as $referencedVersion) {
            if ($referencedVersion !== $version) {
                $errors[] = "{$relativePath} references tag {$referencedVersion}; expected {$version}";
            }
        }

        preg_match_all('/\^0\.\d+(?:\.\d+)?/', $contents, $constraintMatches);
        foreach ($constraintMatches[0] as $referencedConstraint) {
            if ($referencedConstraint !== $constraint) {
                $errors[] = "{$relativePath} references {$referencedConstraint}; expected {$constraint}";
            }
        }
    }

    return $errors;
}

/**
 * Every Markdown page under a directory, as repository-relative paths, sorted
 * so the error list is stable.
 *
 * @return list<string>
 */
function markdownFilesUnder(string $root, string $relativeDirectory): array
{
    $directory = "{$root}/{$relativeDirectory}";
    if (!is_dir($directory)) {
        return [];
    }

    $paths = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        if (!in_array(strtolower($file->getExtension()), ['md', 'mdx'], true)) {
            continue;
        }
        $paths[] = $relativeDirectory . '/' . str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            substr($file->getPathname(), strlen($directory) + 1),
        );
    }
    sort($paths);

    return $paths;
}

function generateReleaseArtifacts(string $root): void
{
    $manifest = readJson("{$root}/release/manifest.json");
    $runtime = arrayValue($manifest, 'runtime');

    $actionStamp = "{$root}/action/runtime-version";
    if (is_file($actionStamp)) {
        writeTextAtomically($actionStamp, stringValue($runtime, 'version') . "\n");
    }

    $cliStamp = "{$root}/packages/cli/src/Release/RuntimeVersion.php";
    if (is_file($cliStamp)) {
        writeTextAtomically($cliStamp, renderRuntimeVersion($manifest));
    }

    $supportedCoreStamp = "{$root}/cloudflare/worker/release/supported-core";
    if (is_file($supportedCoreStamp)) {
        writeTextAtomically($supportedCoreStamp, stringValue(arrayValue($manifest, 'core'), 'supported') . "\n");
    }

    $compatibilityPath = "{$root}/docs-site/src/content/docs/reference/compatibility.md";
    writeTextAtomically($compatibilityPath, renderCompatibility($root, $manifest));

    generateCompatibilityBlocks($root, $manifest);

    fwrite(STDOUT, "Generated release-owned compatibility artifacts.\n");
}

function getManifestValue(string $root, string $path): void
{
    if ($path === '') {
        throw new InvalidArgumentException('get requires a dotted manifest path');
    }

    $value = readJson("{$root}/release/manifest.json");
    foreach (explode('.', $path) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            throw new InvalidArgumentException("Unknown manifest path: {$path}");
        }
        $value = $value[$key];
    }

    if (!is_scalar($value)) {
        throw new InvalidArgumentException("Manifest path is not scalar: {$path}");
    }

    fwrite(STDOUT, (string) $value . "\n");
}

function setReleaseStatus(string $root, string $status): void
{
    assertStatus($status);
    $manifestPath = "{$root}/release/manifest.json";
    $manifest = readJson($manifestPath);
    $manifest['status'] = $status;
    writeJsonAtomically($manifestPath, $manifest);
    fwrite(STDOUT, "Release status set to {$status}. Run the release check before committing.\n");
}

function setReleaseVersion(string $root, string $version, string $status): void
{
    assertVersion($version);
    assertStatus($status);

    $manifestPath = "{$root}/release/manifest.json";
    $manifest = readJson($manifestPath);
    $previousVersion = stringValue($manifest, 'version');
    $constraint = composerConstraint($version);
    $manifest['version'] = $version;
    $manifest['status'] = $status;
    $manifest['runtime']['version'] = $version;
    $manifest['core']['supported'] = $constraint;

    /** @var array<string, array<string, mixed>> $jsonUpdates */
    $jsonUpdates = [$manifestPath => $manifest];

    foreach (PACKAGE_NAMES as $packageName) {
        $path = "{$root}/packages/" . packageSlug($packageName) . '/composer.json';
        $package = readJson($path);
        $package['version'] = $version;
        foreach (['require', 'require-dev'] as $section) {
            foreach (($package[$section] ?? []) as $dependency => $_) {
                if (in_array($dependency, PACKAGE_NAMES, true)) {
                    $package[$section][$dependency] = $constraint;
                }
            }
        }
        $jsonUpdates[$path] = $package;
    }

    // The root composer.json wires the packages via path repositories, and CI
    // resolves it fresh (no committed lock): its atoms/* constraints must
    // follow the coordinated version or the canonical path repo becomes
    // unsatisfiable.
    $rootComposerPath = "{$root}/composer.json";
    $rootComposer = readJson($rootComposerPath);
    foreach (['require', 'require-dev'] as $section) {
        foreach (($rootComposer[$section] ?? []) as $dependency => $_) {
            if (in_array($dependency, PACKAGE_NAMES, true)) {
                $rootComposer[$section][$dependency] = $constraint;
            }
        }
    }
    $jsonUpdates[$rootComposerPath] = $rootComposer;

    foreach ($jsonUpdates as $path => $data) {
        writeJsonAtomically($path, $data);
    }

    $actionStamp = "{$root}/action/runtime-version";
    if (is_file($actionStamp)) {
        writeTextAtomically($actionStamp, $version . "\n");
    }

    $cliStamp = "{$root}/packages/cli/src/Release/RuntimeVersion.php";
    if (is_file($cliStamp)) {
        writeTextAtomically($cliStamp, renderRuntimeVersion($manifest));
    }

    $supportedCoreStamp = "{$root}/cloudflare/worker/release/supported-core";
    if (is_file($supportedCoreStamp)) {
        writeTextAtomically($supportedCoreStamp, $constraint . "\n");
    }

    $changelogPath = "{$root}/CHANGELOG.md";
    $changelog = (string) file_get_contents($changelogPath);
    if (!str_contains($changelog, "## [{$version}]")) {
        $marker = "\n## [{$previousVersion}]";
        $replacement = "\n## [{$version}] - Unreleased\n\n- Release notes pending.\n{$marker}";
        if (!str_contains($changelog, $marker)) {
            throw new RuntimeException('Could not find the current release heading in CHANGELOG.md');
        }
        $changelog = str_replace($marker, $replacement, $changelog);
        $changelog .= "\n[{$version}]: https://github.com/AtomsPHP/atoms/releases/tag/v{$version}\n";
        writeTextAtomically($changelogPath, $changelog);
    }

    $compatibilityPath = "{$root}/docs-site/src/content/docs/reference/compatibility.md";
    writeTextAtomically($compatibilityPath, renderCompatibility($root, $manifest));

    generateCompatibilityBlocks($root, $manifest);

    fwrite(STDOUT, "Coordinated version set to {$version} ({$status}). Run the release check before committing.\n");
}

function splitPackage(string $root, string $slug, string $destination): void
{
    assertPackageSlug($slug);
    if ($destination === '') {
        throw new InvalidArgumentException('split requires an empty destination directory');
    }

    $source = "{$root}/packages/{$slug}";
    assertEmptyDestination($destination);
    copyTree($source, $destination);
    validateSplitDirectory($destination, "atoms/{$slug}");
    validateComposerManifest($destination, "atoms/{$slug}");
    fwrite(STDOUT, "Exported atoms/{$slug} to {$destination}.\n");
}

function splitAll(string $root, string $destination): void
{
    if ($destination === '') {
        throw new InvalidArgumentException('split-all requires an empty destination directory');
    }
    assertEmptyDestination($destination);

    foreach (PACKAGE_NAMES as $packageName) {
        $slug = packageSlug($packageName);
        $target = "{$destination}/{$slug}";
        mkdir($target, 0777, true);
        copyTree("{$root}/packages/{$slug}", $target);
        validateSplitDirectory($target, $packageName);
        validateComposerManifest($target, $packageName);
    }

    fwrite(STDOUT, "Exported and validated all Composer package trees in {$destination}.\n");
}

function validateSplits(string $root): void
{
    $temporary = sys_get_temp_dir() . '/atoms-release-splits-' . bin2hex(random_bytes(8));
    mkdir($temporary, 0700, true);
    file_put_contents("{$temporary}/.atoms-release-temp", "owned\n");

    try {
        foreach (PACKAGE_NAMES as $packageName) {
            $slug = packageSlug($packageName);
            $target = "{$temporary}/{$slug}";
            mkdir($target, 0777, true);
            copyTree("{$root}/packages/{$slug}", $target);
            validateSplitDirectory($target, $packageName);
            validateComposerManifest($target, $packageName);
        }
    } finally {
        removeOwnedTemporaryTree($temporary);
    }

    fwrite(STDOUT, "All eight Composer split trees are self-contained and valid.\n");
}

/** @param array<string, mixed> $manifest @return list<string> */
function validateManifest(array $manifest): array
{
    $errors = [];
    if (($manifest['schema'] ?? null) !== 1) {
        $errors[] = 'manifest schema must be 1';
    }

    try {
        assertVersion(stringValue($manifest, 'version'));
        assertStatus(stringValue($manifest, 'status'));
    } catch (Throwable $error) {
        $errors[] = $error->getMessage();
    }

    foreach (['runtime', 'php', 'core', 'composer'] as $key) {
        if (!is_array($manifest[$key] ?? null)) {
            $errors[] = "manifest.{$key} must be an object";
        }
    }

    if (($manifest['runtime']['package'] ?? null) !== '@atomsphp/runtime-cloudflare') {
        $errors[] = 'runtime.package must be @atomsphp/runtime-cloudflare';
    }
    if (($manifest['runtime']['node'] ?? null) !== '22') {
        $errors[] = 'runtime.node must be 22 for the 0.1 line';
    }
    if (($manifest['runtime']['wrangler'] ?? null) !== '4.118.0') {
        $errors[] = 'runtime.wrangler must match the pinned Worker lockfile';
    }
    if (($manifest['runtime']['guest_php'] ?? null) !== '8.3') {
        $errors[] = 'runtime.guest_php must be 8.3, the frozen core API\'s PHP target';
    }
    if (($manifest['php']['tested'] ?? null) !== ['8.3', '8.4']) {
        $errors[] = 'php.tested must match the CI matrix: 8.3 and 8.4';
    }

    return $errors;
}

function validateSplitDirectory(string $directory, string $packageName): void
{
    foreach (['composer.json', 'README.md', 'LICENSE'] as $file) {
        if (!is_file("{$directory}/{$file}")) {
            throw new RuntimeException("{$packageName} split is missing {$file}");
        }
    }

    $composer = readJson("{$directory}/composer.json");
    if (($composer['name'] ?? null) !== $packageName) {
        throw new RuntimeException("{$packageName} split has the wrong Composer name");
    }
    if (($composer['support']['issues'] ?? null) !== 'https://github.com/AtomsPHP/atoms/issues') {
        throw new RuntimeException("{$packageName} split does not redirect issues to the monorepo");
    }
    $readme = (string) file_get_contents("{$directory}/README.md");
    if (!str_contains($readme, 'read-only distribution mirror')) {
        throw new RuntimeException("{$packageName} split README does not state the mirror policy");
    }
}

function validateComposerManifest(string $directory, string $packageName): void
{
    $output = [];
    $exitCode = 0;
    exec(
        'composer validate --no-check-all ' . escapeshellarg("{$directory}/composer.json") . ' 2>&1',
        $output,
        $exitCode,
    );
    if ($exitCode !== 0) {
        throw new RuntimeException(
            "{$packageName} split failed Composer validation:\n" . implode("\n", $output),
        );
    }
}

/** @param array<string, mixed> $manifest */
function renderCompatibility(string $root, array $manifest): string
{
    $version = stringValue($manifest, 'version');
    $runtime = arrayValue($manifest, 'runtime');
    $php = arrayValue($manifest, 'php');
    $core = arrayValue($manifest, 'core');
    $testedPhp = implode(' and ', array_map(
        static fn (mixed $value): string => 'PHP ' . (string) $value,
        is_array($php['tested'] ?? null) ? $php['tested'] : [],
    ));
    $compatibility = laravelCompatibility($root, $manifest);
    $adapterMajors = formatMajors($compatibility['adapter']);
    $bridgeMajors = formatMajors($compatibility['bridge']);
    $ciRows = implode("\n", array_map(
        static fn (array $entry): string => "| Laravel {$entry['version']} | `{$entry['testbench']}` | {$testedPhp} |",
        $compatibility['ci'],
    ));
    $untested = array_values(array_diff($compatibility['adapter'], array_column($compatibility['ci'], 'version')));
    $untestedNote = $untested === [] ? '' : 'Laravel ' . formatMajors($untested) . " is allowed by the adapter's dependencies but has no current CI coverage.\n";
    [$major, $minor] = explode('.', $version, 3);
    $releaseLine = "{$major}.{$minor}";

    return <<<MARKDOWN
---
title: Compatibility
description: The versions that make up Atoms {$releaseLine} and the toolchain it supports.
---

Atoms releases its PHP packages, Worker runtime, and deploy Action together,
under one version number.

| Component | {$releaseLine} support |
|---|---|
| `atoms/core` | `{$core['supported']}`; a stable API that grows by addition |
| `atoms/client`, adapters, testing, rules, CLI | `{$core['supported']}` |
| `atoms/database-illuminate` | `{$core['supported']}`, installed inside the Atom through `atoms-composer.json` |
| `{$runtime['package']}` | `{$runtime['version']}` |
| Deploy Action | `AtomsPHP/atoms/action@v{$version}` (the tag never moves) |

Keep every component on the same release. If your `atoms/core` version is
outside the range the runtime supports, the Worker rejects the bundle with
[ATOMS-E043](/reference/errors/#atoms-e043).

`atoms-worker/atoms-runtime.json` records the runtime release your Worker
directory came from, and it must match the CLI's release. `atoms dev` and
`atoms deploy` check it before building and report
[ATOMS-E108](/reference/errors/#atoms-e108) if it is missing or different.
After updating the PHP packages, follow
[Upgrade the runtime](/guides/deploy/#upgrade-the-runtime).

Before 1.0, APIs outside `atoms/core` may change between minor versions. Patch
releases stay within the declared Composer constraints. Commit your lockfiles
so application and Worker installs are repeatable.

## Host and toolchain support

| Component | Dependency constraint | Current qualified toolchain | CI coverage |
|---|---|---|---|
| Host PHP | `{$php['constraint']}` | {$testedPhp} | {$testedPhp} |
| Node.js | `>={$runtime['node']}` | Node.js {$runtime['node']} | Node.js {$runtime['node']} |
| Wrangler | `{$runtime['wrangler']}` (exact pin) | Wrangler {$runtime['wrangler']} | Wrangler {$runtime['wrangler']} |

The listed versions are the current qualified host and toolchain baseline for
the documented path. A successful install on a version allowed by a broader
dependency constraint does not qualify that version: its compatibility is not
established by current CI and support is not automatic. Use the listed baseline.
CI coverage identifies version lines, not every patch release or dependency
combination. A smoke test on a later version does not establish complete framework
support; the framework-major matrix below is separate.

Host PHP runs the application, Composer, Artisan, the Atoms CLI, and tests. The
Worker uses a PHP {$runtime['guest_php']} interpreter compiled to WebAssembly,
supplied by the runtime release. Selecting host PHP does not select or recompile
that guest interpreter.

## Laravel and Illuminate

### Application-side dependency compatibility

`atoms/laravel` allows Laravel {$adapterMajors} through its Illuminate dependencies. These are dependency constraints;
they do not imply that every version is covered by CI.

### CI combinations

The test suite runs each Laravel version below with each listed host PHP version.

| Laravel | Orchestra Testbench constraint | Host PHP |
|---|---|---|
{$ciRows}

{$untestedNote}
### Atom-side dependencies

The optional `atoms/database-illuminate` bridge runs inside an Atom and allows
Illuminate Database {$bridgeMajors}. Its dependencies are separate from the
application-side Laravel adapter.

MARKDOWN;
}

/** @return list<string> */
function illuminateMajors(string $constraint, string $name): array
{
    if (!preg_match('/^\^[1-9][0-9]*\.0(?:\s*\|\|\s*\^[1-9][0-9]*\.0)*$/', $constraint)) {
        throw new RuntimeException("Unsupported Illuminate constraint for {$name}: {$constraint}");
    }
    preg_match_all('/\^([1-9][0-9]*)\.0/', $constraint, $matches);
    $majors = $matches[1];
    if (count($majors) !== count(array_unique($majors))) {
        throw new RuntimeException("Duplicate Illuminate major for {$name}");
    }
    sort($majors, SORT_NUMERIC);
    return $majors;
}

/** @param array<string, mixed> $manifest @return array{adapter: list<string>, bridge: list<string>, ci: list<array{version: string, testbench: string}>} */
function laravelCompatibility(string $root, array $manifest): array
{
    $adapter = readJson("{$root}/packages/laravel/composer.json");
    $majors = null;
    foreach (arrayValue($adapter, 'require') as $name => $constraint) {
        if (!str_starts_with($name, 'illuminate/')) {
            continue;
        }
        if (!is_string($constraint)) {
            throw new RuntimeException("Invalid Illuminate constraint for {$name}");
        }
        $current = illuminateMajors($constraint, $name);
        if ($majors !== null && $majors !== $current) {
            throw new RuntimeException('Laravel adapter Illuminate constraints disagree');
        }
        $majors = $current;
    }
    if ($majors === null) {
        throw new RuntimeException('Laravel adapter has no Illuminate requirements');
    }
    $bridge = readJson("{$root}/packages/database-illuminate/composer.json");
    $bridgeMajors = illuminateMajors(stringValue(arrayValue($bridge, 'require'), 'illuminate/database'), 'illuminate/database');
    $ci = arrayValue($manifest, 'laravel')['ci'] ?? null;
    if (!is_array($ci) || !array_is_list($ci) || $ci === []) {
        throw new RuntimeException('laravel.ci must be a non-empty list');
    }
    $seen = [];
    foreach ($ci as $entry) {
        if (!is_array($entry) || array_diff(array_keys($entry), ['version', 'testbench']) !== []
            || !is_string($entry['version'] ?? null) || !preg_match('/^[1-9][0-9]*$/', $entry['version'])
            || !is_string($entry['testbench'] ?? null) || !preg_match('/^\^[1-9][0-9]*\.0$/', $entry['testbench'])
            || in_array($entry['version'], $seen, true) || !in_array($entry['version'], $majors, true)) {
            throw new RuntimeException('Invalid or unsupported laravel.ci entry');
        }
        $seen[] = $entry['version'];
    }
    return ['adapter' => $majors, 'bridge' => $bridgeMajors, 'ci' => $ci];
}

/** @param list<string> $majors */
function formatMajors(array $majors): string
{
    if (count($majors) < 2) {
        return implode('', $majors);
    }
    $last = array_pop($majors);
    return implode(', ', $majors) . ' and ' . $last;
}

/** @param array<string, mixed> $manifest @return array<string, array{string, string, string}> */
function compatibilityBlocks(string $root, array $manifest): array
{
    $compatibility = laravelCompatibility($root, $manifest);
    $adapter = formatMajors($compatibility['adapter']);
    $ciVersions = formatMajors(array_column($compatibility['ci'], 'version'));
    $untested = array_values(array_diff($compatibility['adapter'], array_column($compatibility['ci'], 'version')));
    $note = "The Laravel adapter allows Laravel {$adapter} through its Illuminate dependencies. CI tests Laravel {$ciVersions}.";
    if ($untested !== []) {
        $note .= ' Laravel ' . formatMajors($untested) . ' is allowed but is not currently tested in CI.';
    }
    $note .= ' See [Laravel and Illuminate compatibility](/reference/compatibility/#laravel-and-illuminate).';
    $php = arrayValue($manifest, 'php')['tested'] ?? null;
    if (!is_array($php) || !array_is_list($php) || $php === [] || array_filter($php, static fn ($v): bool => !is_string($v) || !preg_match('/^[0-9]+\.[0-9]+$/', $v))) {
        throw new RuntimeException('php.tested must be a non-empty list of PHP versions');
    }
    $versions = implode(', ', array_map(static fn (string $v): string => "'{$v}'", $php));
    $matrix = "      matrix:\n        php-version: [{$versions}]\n        laravel:";
    foreach ($compatibility['ci'] as $entry) {
        $matrix .= "\n          - {version: '{$entry['version']}', testbench: '{$entry['testbench']}'}";
    }
    $start = '<!-- BEGIN generated Laravel compatibility -->';
    $end = '<!-- END generated Laravel compatibility -->';
    return [
        'docs-site/src/content/docs/getting-started/install.md' => [$start, $end, $note],
        'docs-site/src/content/docs/getting-started/laravel.md' => [$start, $end, $note],
        '.github/workflows/ci.yml' => ['      # BEGIN generated Laravel matrix', '      # END generated Laravel matrix', $matrix],
    ];
}

function replaceTaggedBlock(string $contents, string $start, string $end, string $body): string
{
    if (substr_count($contents, $start) !== 1 || substr_count($contents, $end) !== 1) {
        throw new RuntimeException("Expected one pair of generated block markers: {$start}");
    }
    $from = strpos($contents, $start) + strlen($start);
    $to = strpos($contents, $end);
    if ($to < $from) {
        throw new RuntimeException("Generated block markers are out of order: {$start}");
    }
    return substr($contents, 0, $from) . "\n{$body}\n" . substr($contents, $to);
}

/** @param array<string, mixed> $manifest */
function generateCompatibilityBlocks(string $root, array $manifest): void
{
    foreach (compatibilityBlocks($root, $manifest) as $path => [$start, $end, $body]) {
        $absolute = "{$root}/{$path}";
        writeTextAtomically($absolute, replaceTaggedBlock((string) file_get_contents($absolute), $start, $end, $body));
    }
}

/** @param array<string, mixed> $manifest */
function renderRuntimeVersion(array $manifest): string
{
    $version = stringValue($manifest, 'version');
    $runtime = arrayValue($manifest, 'runtime');
    $package = stringValue($runtime, 'package');
    $runtimeVersion = stringValue($runtime, 'version');

    return <<<PHP
<?php

declare(strict_types=1);

namespace Atoms\Cli\Release;

use Atoms\Cli\Process\ShellArg;

/**
 * Generated from release/manifest.json. Do not edit by hand.
 */
final class RuntimeVersion
{
    public const PACKAGE = '{$package}';

    public const VERSION = '{$runtimeVersion}';

    public const CORE_VERSION = '{$version}';

    /** The committed Worker directory, relative to atoms.json. */
    public const WORKER_DIR = 'atoms-worker';

    public static function scaffoldCommand(string \$target = self::WORKER_DIR): string
    {
        return sprintf(
            'npm exec --yes --package=%s@%s -- atoms-runtime-cloudflare init %s',
            self::PACKAGE,
            self::VERSION,
            ShellArg::quote(\$target),
        );
    }

    public static function upgradeCommand(string \$target = self::WORKER_DIR): string
    {
        return sprintf(
            'npm exec --yes --package=%s@%s -- atoms-runtime-cloudflare upgrade %s',
            self::PACKAGE,
            self::VERSION,
            ShellArg::quote(\$target),
        );
    }
}
PHP;
}

/** @return array<string, mixed> */
function readJson(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("Missing JSON file: {$path}");
    }
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException("Expected a JSON object: {$path}");
    }
    return $decoded;
}

/** @param array<string, mixed> $data */
function writeJsonAtomically(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    writeTextAtomically($path, $json);
}

function writeTextAtomically(string $path, string $contents): void
{
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
    if (file_put_contents($temporary, $contents) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Could not atomically write {$path}");
    }
}

/** @param array<string, mixed> $array */
function stringValue(array $array, string $key): string
{
    if (!isset($array[$key]) || !is_string($array[$key])) {
        throw new RuntimeException("Expected {$key} to be a string");
    }
    return $array[$key];
}

/** @param array<string, mixed> $array @return array<string, mixed> */
function arrayValue(array $array, string $key): array
{
    if (!isset($array[$key]) || !is_array($array[$key])) {
        throw new RuntimeException("Expected {$key} to be an object");
    }
    return $array[$key];
}

function assertVersion(string $version): void
{
    if (preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?$/', $version) !== 1) {
        throw new InvalidArgumentException("Invalid semantic version: {$version}");
    }
}

function assertStatus(string $status): void
{
    if (!in_array($status, ['candidate', 'ready'], true)) {
        throw new InvalidArgumentException('Release status must be candidate or ready');
    }
}

function composerConstraint(string $version): string
{
    [$major, $minor] = array_map('intval', explode('.', $version, 3));
    return "^{$major}.{$minor}";
}

function packageSlug(string $packageName): string
{
    return substr($packageName, strlen('atoms/'));
}

function assertPackageSlug(string $slug): void
{
    if (!in_array("atoms/{$slug}", PACKAGE_NAMES, true)) {
        throw new InvalidArgumentException("Unknown Composer package slug: {$slug}");
    }
}

function assertEmptyDestination(string $destination): void
{
    if (file_exists($destination) && !is_dir($destination)) {
        throw new RuntimeException("Destination is not a directory: {$destination}");
    }
    if (!is_dir($destination) && !mkdir($destination, 0777, true)) {
        throw new RuntimeException("Could not create destination: {$destination}");
    }
    $entries = array_values(array_diff(scandir($destination) ?: [], ['.', '..']));
    if ($entries !== []) {
        throw new RuntimeException("Destination must be empty: {$destination}");
    }
}

function copyTree(string $source, string $destination): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);
        $target = "{$destination}/{$relative}";
        if ($item->isLink()) {
            throw new RuntimeException("Package split cannot contain symlinks: {$relative}");
        }
        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0777, true)) {
                throw new RuntimeException("Could not create split directory: {$target}");
            }
            continue;
        }
        if (!copy($item->getPathname(), $target)) {
            throw new RuntimeException("Could not copy split file: {$relative}");
        }
    }
}

function removeOwnedTemporaryTree(string $directory): void
{
    if (!str_starts_with($directory, sys_get_temp_dir() . '/atoms-release-splits-')
        || !is_file("{$directory}/.atoms-release-temp")) {
        throw new RuntimeException("Refusing to remove unowned temporary directory: {$directory}");
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}
