<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests;

use PHPUnit\Framework\TestCase;

/**
 * integration-plan §5.3: "if the skeleton needs anything from atoms/laravel,
 * the layering is wrong." This is the mechanical check for that — atoms/symfony
 * depends on atoms/client only, so no file under src/ may ever reference
 * Atoms\Laravel\* or Illuminate\* (docs/conventions.md, CLAUDE.md hard rules).
 */
final class LayeringTest extends TestCase
{
    public function testSourceNeverReferencesLaravelOrIlluminate(): void
    {
        $srcDir = \dirname(__DIR__) . '/src';
        self::assertDirectoryExists($srcDir);

        $forbidden = ['Atoms\\Laravel', 'Illuminate\\'];
        $violations = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);

            foreach ($forbidden as $needle) {
                if (str_contains($contents, $needle)) {
                    $violations[] = $file->getPathname() . ' contains "' . $needle . '"';
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "atoms/symfony must never reference atoms/laravel or Illuminate:\n" . implode("\n", $violations),
        );
    }
}
