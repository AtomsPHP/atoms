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
];

$root = dirname(__DIR__, 2);
$command = $argv[1] ?? 'help';

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

Package slugs: core, client, laravel, symfony, testing, phpstan-rules, cli

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
        $errors[] = 'php.constraint must remain ^8.3 for the frozen PHP 8.3 ABI';
    }

    $listedPackages = $composer['packages'] ?? null;
    if ($listedPackages !== PACKAGE_NAMES) {
        $errors[] = 'composer.packages must list the seven packages once, in release order';
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

    $compatibilityPath = "{$root}/site/src/content/docs/reference/compatibility.md";
    if (is_file($compatibilityPath)
        && (string) file_get_contents($compatibilityPath) !== renderCompatibility($manifest)) {
        $errors[] = 'the generated compatibility page is stale; run the release generator';
    }

    if ($errors !== []) {
        throw new RuntimeException("release metadata is inconsistent:\n- " . implode("\n- ", $errors));
    }

    fwrite(STDOUT, "Release {$version} ({$manifest['status']}) is internally consistent.\n");
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

    $compatibilityPath = "{$root}/site/src/content/docs/reference/compatibility.md";
    if (is_file($compatibilityPath)) {
        writeTextAtomically($compatibilityPath, renderCompatibility($manifest));
    }

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

    $compatibilityPath = "{$root}/site/src/content/docs/reference/compatibility.md";
    if (is_file($compatibilityPath)) {
        writeTextAtomically($compatibilityPath, renderCompatibility($manifest));
    }

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

    fwrite(STDOUT, "All seven Composer split trees are self-contained and valid.\n");
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
        $errors[] = 'runtime.guest_php must be 8.3 for the frozen ABI';
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
function renderCompatibility(array $manifest): string
{
    $version = stringValue($manifest, 'version');
    $runtime = arrayValue($manifest, 'runtime');
    $php = arrayValue($manifest, 'php');
    $core = arrayValue($manifest, 'core');
    $testedPhp = implode(' and ', array_map(
        static fn (mixed $value): string => 'PHP ' . (string) $value,
        is_array($php['tested'] ?? null) ? $php['tested'] : [],
    ));
    [$major, $minor] = explode('.', $version, 3);
    $releaseLine = "{$major}.{$minor}";

    return <<<MARKDOWN
---
title: Compatibility
description: The coordinated versions and supported host toolchain for Atoms {$releaseLine}.
---

Atoms releases its PHP packages, Worker runtime, and deployment Action as one compatible line.

| Component | {$releaseLine} support |
|---|---|
| `atoms/core` | `{$core['supported']}` frozen, additive ABI |
| `atoms/client`, adapters, testing, rules, CLI | `{$core['supported']}` |
| `{$runtime['package']}` | `{$runtime['version']}`, co-versioned with the release |
| Deploy Action | immutable `AtomsPHP/atoms/action@v{$version}` |
| Host PHP | `{$php['constraint']}`; tested on {$testedPhp} |
| Guest PHP | PHP {$runtime['guest_php']} WebAssembly |
| Node.js | {$runtime['node']} |
| Wrangler | {$runtime['wrangler']} (exact runtime-template pin) |

Use matching {$releaseLine} release artifacts. The CLI stamps the core ABI version into the bundle manifest, and the Worker rejects an unsupported core/runtime pairing with [ATOMS-E043](/reference/errors/#atoms-e043) instead of attempting to run it.

The runtime scaffold command printed by `atoms init` and used by the deploy Action is generated from the same release manifest as the tag. It is not an independently moving “latest” dependency.

Pre-1.0 APIs outside `atoms/core` may change between minor versions. Package patch releases remain within the declared Composer constraints; use lockfiles for repeatable application and Worker installs.
MARKDOWN;
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

/**
 * Generated from release/manifest.json. Do not edit by hand.
 */
final class RuntimeVersion
{
    public const PACKAGE = '{$package}';

    public const VERSION = '{$runtimeVersion}';

    public const CORE_VERSION = '{$version}';

    public static function scaffoldCommand(string \$target = '.atoms/worker'): string
    {
        return sprintf(
            'npm exec --yes --package=%s@%s -- atoms-runtime-cloudflare init %s',
            self::PACKAGE,
            self::VERSION,
            \$target,
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
