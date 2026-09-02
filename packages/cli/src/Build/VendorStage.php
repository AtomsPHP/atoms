<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use Atoms\Cli\Config\Timeouts;
use Atoms\Cli\Process\ProcessRunner;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * Stage 8: resolve `atoms-composer.json` and turn the result into bundle
 * entries, so approved vendor packages actually ship inside the bundle and run
 * in the guest.
 *
 * How it holds the build rules:
 *
 *  - **Never executes customer code**: `composer install` runs with
 *    `--no-scripts --no-plugins` in an isolated directory.
 *  - **Deterministic**: the first successful resolution writes
 *    `atoms-composer.lock` back next to `atoms-composer.json` (Composer's own
 *    lock format); committed, it pins every later resolution to the same
 *    bytes. The generated autoload file is sorted, and the entry list is
 *    sorted, so identical resolutions produce identical bundles.
 *  - **Offline after the first resolution**: the pruned tree is cached under
 *    `.atoms/vendor-cache/<key>` keyed by the sha256 of atoms-composer.json +
 *    lock, so `atoms dev`'s rebuild loop and a clean `atoms build` with an
 *    unchanged lock never touch the network (or composer) again.
 *
 * What ships: every `.php` file under vendor/ (data files like Carbon's
 * locale tables are `.php` too) plus each package's LICENSE* files, and one
 * generated `vendor/atoms-vendor-autoload.php` — a classmap + function-file
 * loader built from Composer's own optimized autoload output, because
 * `bundle_format 0` carries no class map and the guest's line-scanning
 * autoloader must not have to read a thousand vendor files at activation.
 *
 * The tree ships **unprefixed**. php-scoper is not run: prefixing vendor
 * namespaces without also rewriting the customer's own Atom code (which
 * references those namespaces) would break every vendor call site, and the
 * guest has no other occupant to collide with — it loads exactly atoms/core,
 * the Atoms\Cf runtime, and this bundle. Scoping remains available as future
 * hardening; the manifest's scoper_prefix stays what it always was, a
 * fingerprint of the customer tree.
 */
final class VendorStage
{
    /** Bundle-relative path of the generated autoloader. */
    public const AUTOLOAD_PATH = 'vendor/atoms-vendor-autoload.php';

    /**
     * Pruned files with one of these extensions look like runtime data a
     * package might file_get_contents() in the guest (where they will not
     * exist), so the build surfaces them by name. Docs and metadata
     * extensions are deliberately absent — listing every readme would bury
     * the signal.
     */
    private const DATA_EXTENSIONS = ['json', 'txt', 'csv', 'tsv', 'xml', 'yml', 'yaml', 'ini', 'dat'];

    /**
     * The two Composer runtime files a dependency may genuinely load at
     * runtime (`Composer\InstalledVersions` is in every optimized classmap
     * and reads installed.php beside itself). Everything else under
     * vendor/composer/ is install-time machinery the generated autoloader
     * replaces.
     */
    private const COMPOSER_RUNTIME_KEEP = ['composer/InstalledVersions.php', 'composer/installed.php'];

    public function __construct(private readonly ProcessRunner $runner)
    {
    }

    /**
     * @throws AtomsError ATOMS-E079 when resolution fails
     */
    public function resolve(string $rootDir): VendorTree
    {
        $rootDir = rtrim($rootDir, '/');
        $composerJson = $rootDir . '/atoms-composer.json';
        $lockPath = $rootDir . '/atoms-composer.lock';

        $jsonBytes = $this->readOrFail($composerJson);
        $lockBytes = is_file($lockPath) ? $this->readOrFail($lockPath) : null;

        if ($lockBytes !== null) {
            $cached = $this->readCache($rootDir, self::cacheKey($jsonBytes, $lockBytes));
            if ($cached !== null) {
                return $cached;
            }
        }

        if ($this->runner->which('composer') === null) {
            throw self::failure('composer is not on PATH, and .atoms/vendor-cache has no tree for the current atoms-composer.json + atoms-composer.lock');
        }

        $work = sys_get_temp_dir() . '/atoms-vendor-' . bin2hex(random_bytes(6));
        if (!@mkdir($work, 0777, true) && !is_dir($work)) {
            throw self::failure("could not create a work directory under {$work}");
        }

        try {
            $this->writeOrFail($work . '/composer.json', self::stagedComposerJson($rootDir, $jsonBytes));
            if ($lockBytes !== null) {
                $this->writeOrFail($work . '/composer.lock', $lockBytes);
            }

            $install = $this->runner->run(
                self::composerCommand(),
                $work,
                ['COMPOSER_ALLOW_SUPERUSER' => '1'],
                self::composerTimeout(),
            );
            if (!$install->ok()) {
                throw self::failure("`composer install` exited with status {$install->exitCode}: " . trim($install->stderr));
            }

            $wroteLock = false;
            if ($lockBytes === null && is_file($work . '/composer.lock')) {
                $lockBytes = $this->readOrFail($work . '/composer.lock');
                $this->writeOrFail($lockPath, $lockBytes);
                $wroteLock = true;
            }

            $pruned = [];
            $entries = $this->collectEntries($work . '/vendor', $pruned);
            sort($pruned, SORT_STRING);
            $packages = $this->installedPackages($work . '/vendor');

            $tree = new VendorTree($entries, $packages, $wroteLock, $pruned);

            if ($lockBytes !== null) {
                $this->writeCache($rootDir, self::cacheKey($jsonBytes, $lockBytes), $tree);
            }

            return $tree;
        } finally {
            self::rmrf($work);
        }
    }

    /**
     * The composer.json staged into the work directory. Path repositories
     * need two rewrites to survive the move: a relative `url` is resolved
     * against the project root (composer runs in a temp dir), and
     * `options.symlink: false` is forced — a symlinked package install is
     * invisible to the tree walk, so nothing would ship.
     */
    public static function stagedComposerJson(string $rootDir, string $jsonBytes): string
    {
        try {
            $decoded = json_decode($jsonBytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $jsonBytes; // AtomsComposerJson already validated/refused upstream
        }
        if (!\is_array($decoded) || !\is_array($decoded['repositories'] ?? null)) {
            return $jsonBytes;
        }

        foreach ($decoded['repositories'] as $i => $repo) {
            if (!\is_array($repo) || ($repo['type'] ?? null) !== 'path' || !\is_string($repo['url'] ?? null)) {
                continue;
            }
            $url = $repo['url'];
            if (!str_starts_with($url, '/')) {
                $url = $rootDir . '/' . $url;
            }
            $decoded['repositories'][$i]['url'] = $url;
            $options = \is_array($repo['options'] ?? null) ? $repo['options'] : [];
            $options['symlink'] = false;
            $decoded['repositories'][$i]['options'] = $options;
        }

        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    private static function composerTimeout(): float
    {
        try {
            return Timeouts::composerInstall();
        } catch (\InvalidArgumentException $e) {
            throw self::failure($e->getMessage());
        }
    }

    /**
     * @return list<string>
     */
    public static function composerCommand(): array
    {
        // --no-scripts/--no-plugins: a build must never execute customer (or
        // dependency) code. Without a composer.lock in the work dir this
        // resolves against the constraints; the lock it leaves behind is what
        // gets written back as atoms-composer.lock.
        return [
            'composer', 'install', '--no-dev', '--no-interaction', '--no-progress',
            '--no-scripts', '--no-plugins', '--ignore-platform-reqs', '--optimize-autoloader',
        ];
    }

    /**
     * Every .php file plus package LICENSE files, and the generated autoload
     * file, as sorted bundle entries. Pruned files that look like runtime
     * data ({@see self::DATA_EXTENSIONS}) are collected into $pruned.
     *
     * @param list<string> $pruned
     * @return list<array{name: string, contents: string}>
     */
    private function collectEntries(string $vendorDir, array &$pruned = []): array
    {
        if (!is_dir($vendorDir)) {
            throw self::failure("composer install produced no vendor directory at {$vendorDir}");
        }

        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($vendorDir, \FilesystemIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $relative = substr($item->getPathname(), \strlen($vendorDir) + 1);
            // Composer's own runtime (vendor/composer/*, vendor/autoload.php,
            // vendor/bin) is replaced by the generated autoload file below —
            // except InstalledVersions.php + installed.php, which real
            // optimized classmaps reference and dependencies genuinely load.
            if (str_starts_with($relative, 'composer/')) {
                if (!\in_array($relative, self::COMPOSER_RUNTIME_KEEP, true)) {
                    continue;
                }
            } elseif (str_starts_with($relative, 'bin/') || $relative === 'autoload.php') {
                continue;
            }
            $base = basename($relative);
            $isPhp = str_ends_with($relative, '.php');
            $isLicense = str_starts_with($base, 'LICENSE') || str_starts_with($base, 'LICENCE');
            if (!$isPhp && !$isLicense) {
                $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
                if (\in_array($extension, self::DATA_EXTENSIONS, true) && $base !== 'composer.json') {
                    $pruned[] = 'vendor/' . $relative;
                }
                continue;
            }
            $contents = $this->readOrFail($item->getPathname());
            // The deploy translator decodes every entry as UTF-8, so refuse
            // here rather than let it mangle the bytes later.
            if (!mb_check_encoding($contents, 'UTF-8')) {
                throw self::failure("vendor/{$relative} is not valid UTF-8; every bundle entry must be, because the deploy translator decodes entries as UTF-8");
            }
            $entries[] = [
                'name' => 'vendor/' . $relative,
                'contents' => $contents,
            ];
        }

        $entries[] = [
            'name' => self::AUTOLOAD_PATH,
            'contents' => $this->generateAutoload($vendorDir),
        ];

        usort($entries, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $entries;
    }

    /**
     * One plain PHP file from Composer's optimized autoload output: an exact
     * classmap plus the ordered function-file requires, all __DIR__-relative
     * so the same file works at any guest mount point.
     */
    private function generateAutoload(string $vendorDir): string
    {
        $classmapPath = $vendorDir . '/composer/autoload_classmap.php';
        if (!is_file($classmapPath)) {
            throw self::failure('composer produced no optimized classmap (autoload_classmap.php missing)');
        }

        /** @var array<string, string> $classmap */
        $classmap = require $classmapPath;

        $filesPath = $vendorDir . '/composer/autoload_files.php';
        /** @var array<string, string> $files */
        $files = is_file($filesPath) ? require $filesPath : [];

        $classLines = [];
        ksort($classmap, SORT_STRING);
        foreach ($classmap as $class => $path) {
            $relative = $this->vendorRelative($vendorDir, $path);
            if ($relative === null) {
                continue; // not under vendor/ — cannot ship, cannot load
            }
            $classLines[] = sprintf('        %s => %s,', var_export($class, true), var_export('/' . $relative, true));
        }

        $fileLines = [];
        foreach (array_values($files) as $path) {
            $relative = $this->vendorRelative($vendorDir, $path);
            if ($relative === null) {
                continue;
            }
            $fileLines[] = sprintf('        %s,', var_export('/' . $relative, true));
        }

        return "<?php\n\n"
            . "// Generated by `atoms build` from Composer's optimized autoload output.\n"
            . "// Classmap + function-file loader for the bundled vendor tree; paths are\n"
            . "// relative to this file's own directory so it works wherever the bundle\n"
            . "// is mounted. Regenerated every build — do not edit.\n\n"
            . "call_user_func(static function (): void {\n"
            . "    \$classes = [\n"
            . implode("\n", $classLines) . "\n"
            . "    ];\n"
            . "    \$dir = __DIR__;\n"
            . "    spl_autoload_register(static function (\$class) use (\$classes, \$dir): void {\n"
            . "        if (isset(\$classes[\$class])) {\n"
            . "            require \$dir . \$classes[\$class];\n"
            . "        }\n"
            . "    });\n"
            . "    foreach ([\n"
            . ($fileLines === [] ? '' : implode("\n", $fileLines) . "\n")
            . "    ] as \$file) {\n"
            . "        require_once \$dir . \$file;\n"
            . "    }\n"
            . "});\n";
    }

    /**
     * @return array<string, string> package => version, sorted by name
     */
    private function installedPackages(string $vendorDir): array
    {
        $path = $vendorDir . '/composer/installed.json';
        if (!is_file($path)) {
            return [];
        }

        try {
            $decoded = json_decode($this->readOrFail($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw self::failure('vendor/composer/installed.json is not valid JSON: ' . $e->getMessage());
        }

        $packages = [];
        foreach ((array) ($decoded['packages'] ?? []) as $package) {
            if (\is_array($package) && \is_string($package['name'] ?? null) && \is_string($package['version'] ?? null)) {
                $packages[$package['name']] = $package['version'];
            }
        }
        ksort($packages, SORT_STRING);

        return $packages;
    }

    private function vendorRelative(string $vendorDir, string $path): ?string
    {
        $real = realpath($path);
        $vendorReal = realpath($vendorDir);
        if ($real === false || $vendorReal === false || !str_starts_with($real, $vendorReal . '/')) {
            return null;
        }

        return substr($real, \strlen($vendorReal) + 1);
    }

    private static function cacheKey(string $jsonBytes, string $lockBytes): string
    {
        return hash('sha256', $jsonBytes . "\0" . $lockBytes);
    }

    private function cacheDir(string $rootDir, string $key): string
    {
        return $rootDir . '/.atoms/vendor-cache/' . $key;
    }

    /**
     * Digest over the cached entries, recorded in meta.json and recomputed on
     * read. A mismatch (corruption, edits, a half-written cache) is a cache
     * miss: composer runs again. The cache is only as trustworthy as the repo
     * it lives in — meta.json itself is editable.
     *
     * @param list<array{name: string, contents: string}> $entries sorted
     */
    private static function entriesDigest(array $entries): string
    {
        $ctx = hash_init('sha256');
        foreach ($entries as $entry) {
            hash_update($ctx, $entry['name'] . "\0" . $entry['contents'] . "\0");
        }

        return hash_final($ctx);
    }

    private function readCache(string $rootDir, string $key): ?VendorTree
    {
        // The cache can never fail a build: an unreadable file, a directory
        // vanishing under the iterator mid-replacement, anything at all — a
        // miss, and composer resolves again.
        try {
            return $this->readCacheOrThrow($rootDir, $key);
        } catch (\Throwable) {
            return null;
        }
    }

    private function readCacheOrThrow(string $rootDir, string $key): ?VendorTree
    {
        $dir = $this->cacheDir($rootDir, $key);
        $metaPath = $dir . '/meta.json';
        if (!is_file($metaPath)) {
            return null;
        }

        try {
            $meta = json_decode($this->readOrFail($metaPath), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null; // a corrupt cache is a miss, not a failure
        }
        if (!\is_array($meta) || !\is_array($meta['packages'] ?? null) || !\is_string($meta['entries_hash'] ?? null)) {
            return null;
        }

        $entries = [];
        $filesDir = $dir . '/files';
        if (!is_dir($filesDir)) {
            return null;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($filesDir, \FilesystemIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $entries[] = [
                'name' => substr($item->getPathname(), \strlen($filesDir) + 1),
                'contents' => $this->readOrFail($item->getPathname()),
            ];
        }
        usort($entries, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        if (!hash_equals($meta['entries_hash'], self::entriesDigest($entries))) {
            return null; // altered or incomplete cache: treat as a miss
        }

        /** @var array<string, string> $packages */
        $packages = $meta['packages'];
        /** @var list<string> $prunedDataFiles */
        $prunedDataFiles = \is_array($meta['pruned_data_files'] ?? null)
            ? array_values(array_map('strval', $meta['pruned_data_files']))
            : [];

        return new VendorTree($entries, $packages, false, $prunedDataFiles);
    }

    private function writeCache(string $rootDir, string $key, VendorTree $tree): void
    {
        $parent = $rootDir . '/.atoms/vendor-cache';

        // Assemble in a dot-prefixed staging dir, then rename() into place.
        // rename() is atomic, so readers never see a half-written cache; the
        // sibling prune below uses glob(), which skips dotfiles, so a racing
        // build cannot delete a stage mid-assembly.
        $stage = $parent . '/.tmp-' . bin2hex(random_bytes(6));
        foreach ($tree->entries as $entry) {
            $path = $stage . '/files/' . $entry['name'];
            if (!is_dir(\dirname($path)) && !@mkdir(\dirname($path), 0777, true) && !is_dir(\dirname($path))) {
                self::rmrf($stage);

                return; // cache is best-effort; the build already has its tree
            }
            if (@file_put_contents($path, $entry['contents']) === false) {
                self::rmrf($stage);

                return;
            }
        }
        if (@file_put_contents($stage . '/meta.json', json_encode(
            [
                'packages' => $tree->packages,
                'pruned_data_files' => $tree->prunedDataFiles,
                'entries_hash' => self::entriesDigest($tree->entries),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n") === false) {
            self::rmrf($stage);

            return;
        }

        // Replace the live tree without a visibility gap: move it aside
        // (rename, atomic), move the stage in, then delete the aside copy.
        // Deleting first left a window where readers saw a missing or
        // half-deleted directory.
        $dir = $this->cacheDir($rootDir, $key);
        $aside = $parent . '/.old-' . bin2hex(random_bytes(6));
        if (is_dir($dir) && !@rename($dir, $aside)) {
            self::rmrf($stage); // cannot displace the live tree; keep it

            return;
        }
        if (!@rename($stage, $dir)) {
            self::rmrf($stage); // a concurrent build won the rename; theirs is as good as ours
            if (is_dir($aside) && !@rename($aside, $dir)) {
                self::rmrf($aside);
            }

            return;
        }
        self::rmrf($aside);

        // One resolution is current at a time; non-dot siblings are stale keys.
        foreach (glob($parent . '/*', GLOB_NOSORT) ?: [] as $sibling) {
            if ($sibling !== $dir) {
                self::rmrf($sibling);
            }
        }
    }

    private function writeOrFail(string $path, string $bytes): void
    {
        if (@file_put_contents($path, $bytes) === false) {
            throw self::failure("could not write {$path}");
        }
    }

    private function readOrFail(string $path): string
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw self::failure("could not read {$path}");
        }

        return $bytes;
    }

    private static function failure(string $reason): AtomsError
    {
        return new AtomsError(
            ErrorCode::VendorResolutionFailed,
            ErrorCatalog::format(ErrorCode::VendorResolutionFailed, ['reason' => $reason]),
        );
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var \SplFileInfo $item */
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
