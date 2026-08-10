<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration;

use Atoms\Cli\Build\Builder;
use Atoms\Cli\Config\AtomsJson;
use PHPUnit\Framework\TestCase;

/**
 * Cross-half contract test: what `atoms build` emits MUST be loadable by the
 * Cloudflare Worker.
 *
 * The two halves keep their own formats on purpose — `atoms build` produces a
 * portable, content-addressed tar.gz plus a schema-1 manifest; the Worker loads
 * a JS module with the guest filesystem inlined — and
 * `cloudflare/worker/scripts/bundle-from-cli.mjs` translates one into the other.
 * That script is the only thing standing between the two, so this is the test
 * that would catch either side drifting away from it.
 *
 * Runs the real translator against a real build. No network: `node` is a local
 * process and the fixture is in-repo.
 */
final class BundleBridgeTest extends TestCase
{
    private const FIXTURE = 'packages/cli/tests/Fixtures/sample-app';

    public function testCliBundleTranslatesIntoALoadableWorkerBundle(): void
    {
        $repo = \dirname(__DIR__, 2);
        $node = (new \Symfony\Component\Process\ExecutableFinder())->find('node');
        if ($node === null) {
            self::markTestSkipped('node is not on PATH; the Worker bundle is built by a Node script.');
        }

        $script = $repo . '/cloudflare/worker/scripts/bundle-from-cli.mjs';
        self::assertFileExists($script);

        $outDir = sys_get_temp_dir() . '/atoms-bundle-bridge-' . bin2hex(random_bytes(6));
        $built = (new Builder())->build(AtomsJson::load($repo . '/' . self::FIXTURE . '/atoms.json'), $outDir, fast: true);

        $output = $outDir . '/bundle.generated.js';
        $process = new \Symfony\Component\Process\Process(
            [$node, $script, $built->bundlePath, $built->manifestPath, $output],
            $repo . '/cloudflare/worker',
        );
        $process->run();

        self::assertSame(
            0,
            $process->getExitCode(),
            "bundle-from-cli.mjs failed:\n" . $process->getErrorOutput(),
        );
        self::assertFileExists($output);

        $module = (string) file_get_contents($output);

        // The host reads `manifest.atoms` keyed by wire type
        // (worker/src/index.js::checkManifest, php/runtime/bootstrap.php::activate).
        self::assertStringContainsString('"GameRoom": {', $module);
        self::assertStringContainsString('"class": "App\\\\Atoms\\\\GameRoom"', $module);

        // Guest paths: the customer's tree under /app, the runtime prelude and
        // the verbatim atoms/core under /atoms.
        self::assertStringContainsString('"file": "/app/app/Atoms/GameRoom.php"', $module);
        self::assertStringContainsString('/app/app/Atoms/GameRoom/migrations/001_create_events.sql', $module);
        self::assertStringContainsString('/app/app/Atoms/GameRoom/migrations/002_add_round_index.sql', $module);
        self::assertStringContainsString('"/atoms/runtime/bootstrap.php"', $module);
        self::assertStringContainsString('"/atoms/core/src/Atom.php"', $module);

        // ErrorCatalog resolves its catalog as __DIR__.'/../../resources/errors.json'
        // from /atoms/core/src/Errors, so this exact path is load-bearing.
        self::assertStringContainsString('"/atoms/core/resources/errors.json"', $module);

        // Provenance: a deployed Worker can be traced back to its bundle.
        self::assertStringContainsString('"content_hash": "' . $built->contentHash . '"', $module);

        // World B never ships: Methods classes run in the monolith.
        self::assertStringNotContainsString('/app/app/Atoms/GameRoom/Methods.php', $module);

        self::rmrf($outDir);
    }

    public function testTranslatorRefusesAnArchiveWhoseContentsDoNotMatchTheManifest(): void
    {
        $repo = \dirname(__DIR__, 2);
        $node = (new \Symfony\Component\Process\ExecutableFinder())->find('node');
        if ($node === null) {
            self::markTestSkipped('node is not on PATH.');
        }

        $outDir = sys_get_temp_dir() . '/atoms-bundle-bridge-' . bin2hex(random_bytes(6));
        $built = (new Builder())->build(AtomsJson::load($repo . '/' . self::FIXTURE . '/atoms.json'), $outDir, fast: true);

        // Tamper with the CONTENTS and keep the original, correct filename —
        // the attack a filename comparison cannot see. `GameR00m` is the same
        // length as `GameRoom`, so every tar header offset and size stays
        // valid and the archive is still structurally readable.
        $tar = (string) gzdecode((string) file_get_contents($built->bundlePath));
        $tampered = str_replace('GameRoom', 'GameR00m', $tar);
        self::assertNotSame($tar, $tampered, 'the fixture must contain the string being tampered with');

        $gz = (string) gzencode($tampered, 9);
        $gz = substr_replace($gz, "\0\0\0\0", 4, 4);
        $gz = substr_replace($gz, "\x03", 9, 1);

        $renamed = $built->bundlePath;
        file_put_contents($renamed, $gz);

        $process = new \Symfony\Component\Process\Process(
            [
                $node,
                $repo . '/cloudflare/worker/scripts/bundle-from-cli.mjs',
                $renamed,
                $built->manifestPath,
                $outDir . '/bundle.generated.js',
            ],
            $repo . '/cloudflare/worker',
        );
        $process->run();

        self::assertNotSame(0, $process->getExitCode(), 'a mismatched pair must not deploy silently');
        self::assertStringContainsString('content_hash', $process->getErrorOutput());

        self::rmrf($outDir);
    }

    /**
     * Deleting `content_hash` must not be a way to opt out of verification.
     * The check was briefly written as `if (cli.content_hash)`, which made
     * removing one line from a hand-edited manifest skip it entirely.
     */
    public function testTranslatorRefusesAManifestWithNoContentHash(): void
    {
        $repo = \dirname(__DIR__, 2);
        $node = (new \Symfony\Component\Process\ExecutableFinder())->find('node');
        if ($node === null) {
            self::markTestSkipped('node is not on PATH.');
        }

        $outDir = sys_get_temp_dir() . '/atoms-bundle-bridge-' . bin2hex(random_bytes(6));
        $built = (new Builder())->build(AtomsJson::load($repo . '/' . self::FIXTURE . '/atoms.json'), $outDir, fast: true);

        foreach ([null, '', 'not-a-hash', 'ABCDEF'] as $i => $bad) {
            /** @var array<string, mixed> $manifest */
            $manifest = json_decode((string) file_get_contents($built->manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if ($bad === null) {
                unset($manifest['content_hash']);
            } else {
                $manifest['content_hash'] = $bad;
            }

            $path = $outDir . "/manifest-{$i}.json";
            file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));

            $process = new \Symfony\Component\Process\Process(
                [$node, $repo . '/cloudflare/worker/scripts/bundle-from-cli.mjs', $built->bundlePath, $path, $outDir . '/out.js'],
                $repo . '/cloudflare/worker',
            );
            $process->run();

            self::assertNotSame(
                0,
                $process->getExitCode(),
                'an unverifiable manifest must be refused, not trusted: ' . var_export($bad, true),
            );
            self::assertStringContainsString('content_hash', $process->getErrorOutput());
        }

        self::rmrf($outDir);
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
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
