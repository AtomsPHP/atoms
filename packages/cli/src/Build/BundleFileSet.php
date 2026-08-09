<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use Atoms\Cli\Config\AtomsJson;

/**
 * The deterministic set of files that ship in a bundle: every Atom and Shared
 * source file, every migration, and atoms-composer.json — sorted by their
 * repo-relative path.
 *
 * @phpstan-type BundleFile array{relative: string, absolute: string}
 */
final class BundleFileSet
{
    /**
     * @param list<BundleFile> $files
     */
    private function __construct(public readonly array $files)
    {
    }

    public static function collect(AtomsJson $config, DiscoveryResult $discovery): self
    {
        $rootDir = $config->rootDir;
        /** @var array<string, string> $paths relative => absolute */
        $paths = [];

        foreach ($discovery->classes as $class) {
            if ($class->kind->isBundled()) {
                $paths[$class->relativePath] = $class->absolutePath;
            }
        }

        foreach ($discovery->ofKind(ClassKind::Atom) as $atom) {
            $dir = self::migrationsDir($atom);
            foreach (self::migrationFiles($dir) as $absolute) {
                $paths[Discovery::relativePath($rootDir, $absolute)] = $absolute;
            }
        }

        $composer = rtrim($rootDir, '/') . '/atoms-composer.json';
        if (is_file($composer)) {
            $paths[Discovery::relativePath($rootDir, $composer)] = $composer;
        }

        ksort($paths, SORT_STRING);

        $files = [];
        foreach ($paths as $relative => $absolute) {
            $files[] = ['relative' => $relative, 'absolute' => $absolute];
        }

        return new self($files);
    }

    public static function migrationsDir(DiscoveredClass $atom): string
    {
        return \dirname($atom->absolutePath) . '/' . basename($atom->absolutePath, '.php') . '/migrations';
    }

    /**
     * @return list<string> sorted absolute paths of NNN_*.sql|.php migration files
     */
    public static function migrationFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $found = glob(rtrim($dir, '/') . '/*.{sql,php}', GLOB_BRACE);
        if ($found === false) {
            return [];
        }
        sort($found, SORT_STRING);

        return $found;
    }

    /**
     * sha256 of the concatenated sorted (path\0contents) — a deterministic
     * fingerprint of the bundled tree, used to derive the scoper prefix.
     */
    public function treeHash(): string
    {
        $ctx = hash_init('sha256');
        foreach ($this->files as $file) {
            $contents = @file_get_contents($file['absolute']);
            if ($contents === false) {
                $contents = '';
            }
            hash_update($ctx, $file['relative'] . "\0" . $contents . "\0");
        }

        return hash_final($ctx);
    }

    public function scoperPrefix(): string
    {
        // Leading "V" keeps the segment a valid PHP namespace even when the
        // hash prefix starts with a digit.
        return 'AtomsScoped\\V' . substr($this->treeHash(), 0, 8);
    }
}
