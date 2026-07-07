<?php

declare(strict_types=1);

namespace Atoms\Testing\Internal;

/**
 * A unique temp directory created under sys_get_temp_dir(), recursively
 * removable exactly once. Used by {@see \Atoms\Testing\AtomHarness} to host
 * each harness's SQLite file.
 *
 * @internal
 */
final class TemporaryDirectory
{
    private bool $deleted = false;

    private function __construct(
        private readonly string $path,
    ) {
    }

    public static function create(string $prefix = 'atoms-testing-'): self
    {
        $base = rtrim(sys_get_temp_dir(), '/');

        do {
            $path = $base . '/' . $prefix . bin2hex(random_bytes(8));
        } while (is_dir($path));

        if (!mkdir($path, 0o777, true) && !is_dir($path)) {
            throw new \RuntimeException("Could not create temporary directory {$path}.");
        }

        return new self($path);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function delete(): void
    {
        if ($this->deleted) {
            return;
        }

        $this->deleted = true;
        self::removeRecursive($this->path);
    }

    private static function removeRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $dir . '/' . $item;

            if (is_dir($full) && !is_link($full)) {
                self::removeRecursive($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($dir);
    }

    public function __destruct()
    {
        $this->delete();
    }
}
