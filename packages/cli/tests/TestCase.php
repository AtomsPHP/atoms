<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests;

use Atoms\Cli\Config\AtomsJson;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Shared helpers: locate the fixture projects and copy one into a scratch
 * directory so mutating commands (init, make:atom, ai:install) never touch the
 * checked-in fixtures.
 */
abstract class TestCase extends BaseTestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function fixtureDir(string $name): string
    {
        return __DIR__ . '/Fixtures/' . $name;
    }

    protected function sampleApp(): AtomsJson
    {
        return AtomsJson::load($this->fixtureDir('sample-app') . '/atoms.json');
    }

    protected function violatingApp(): AtomsJson
    {
        return AtomsJson::load($this->fixtureDir('violating-app') . '/atoms.json');
    }

    /**
     * Four Atom shapes that exercise every answer the manifest's `websocket`
     * key can give (declared handler / directly extends Atom with none /
     * inherited-and-unknowable / case-variant handler name). Kept apart from
     * sample-app, whose atom count and ordering several tests assert exactly.
     */
    protected function websocketShapesApp(): AtomsJson
    {
        return AtomsJson::load($this->fixtureDir('ws-app') . '/atoms.json');
    }

    /**
     * Recursively copy a fixture into a fresh temp dir; returns the copy's root.
     */
    protected function tempCopy(string $fixture): string
    {
        $dest = sys_get_temp_dir() . '/atoms-cli-test-' . bin2hex(random_bytes(6));
        $this->tempDirs[] = $dest;
        $this->copyTree($this->fixtureDir($fixture), $dest);

        return $dest;
    }

    protected function freshDir(): string
    {
        $dir = sys_get_temp_dir() . '/atoms-cli-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->rmrf($dir);
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    private function copyTree(string $src, string $dest): void
    {
        mkdir($dest, 0777, true);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        /** @var \SplFileInfo $item */
        foreach ($items as $item) {
            $target = $dest . '/' . substr($item->getPathname(), \strlen($src) + 1);
            if ($item->isDir()) {
                mkdir($target, 0777, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private function rmrf(string $dir): void
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
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
