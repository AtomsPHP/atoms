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

    /**
     * Four Atom shapes exercising every answer the manifest's `websocket` key
     * can give — declared / directly-extends-Atom-with-none / inherited-and-
     * unknowable / case-variant handler name. See ManifestTest for the
     * generator side; this test drives the SAME fixture through the real
     * bridge, which is the piece ManifestTest cannot see.
     */
    private const WS_FIXTURE = 'packages/cli/tests/Fixtures/ws-app';

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

        // The additive "websocket" manifest field (docs/cloudflare-toolchain.md
        // §3) must survive the translation: GameRoom overrides onConnect(), so
        // the CLI's ManifestGenerator marks it true, and bundle-from-cli.mjs
        // must carry that through rather than dropping it.
        self::assertStringContainsString('"websocket": true', $module);

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

    /**
     * The `websocket` tri-state must survive the bridge unchanged. The failure
     * this pins: `bundle-from-cli.mjs` briefly wrote `websocket: atom.websocket
     * === true`, which coerced the CLI's OMITTED key (the inherited-handler
     * case ManifestGenerator declines to answer) into a definite `false`. The
     * runtime refuses `websocket === false` with a 501 before any Durable
     * Object is touched, so that collapse resurrected a wrongful 501 on the
     * real `atoms deploy` path — on a type whose onConnect works perfectly.
     */
    public function testTheWebsocketTriStateSurvivesTheBridgeIncludingAnInheritedHandler(): void
    {
        $repo = \dirname(__DIR__, 2);
        $node = (new \Symfony\Component\Process\ExecutableFinder())->find('node');
        if ($node === null) {
            self::markTestSkipped('node is not on PATH; the Worker bundle is built by a Node script.');
        }

        $outDir = sys_get_temp_dir() . '/atoms-bundle-bridge-' . bin2hex(random_bytes(6));
        $built = (new Builder())->build(AtomsJson::load($repo . '/' . self::WS_FIXTURE . '/atoms.json'), $outDir, fast: true);

        $output = $outDir . '/bundle.generated.js';
        $process = new \Symfony\Component\Process\Process(
            [$node, $repo . '/cloudflare/worker/scripts/bundle-from-cli.mjs', $built->bundlePath, $built->manifestPath, $output],
            $repo . '/cloudflare/worker',
        );
        $process->run();

        self::assertSame(
            0,
            $process->getExitCode(),
            "bundle-from-cli.mjs failed:\n" . $process->getErrorOutput(),
        );

        $module = (string) file_get_contents($output);

        // Subroom extends Roomish (not Atoms\Atom), so its onConnect is INHERITED
        // and unknowable to a file-parsing discovery: ManifestGenerator OMITS the
        // key, and the bridge must preserve that absence. If the bridge collapses
        // it to `false`, GET /ws/Subroom/:id would 501 before any DO.
        self::assertStringContainsString('"Subroom": {', $module);
        self::assertStringNotContainsString(
            'websocket',
            self::atomEntry($module, 'Subroom'),
            'an inherited handler must OMIT the websocket key through the bridge, not have it collapsed to false',
        );

        // The other three shapes carry through with their definite value: Plain
        // (extends Atoms\Atom directly, no handler) is a provable `false`;
        // Roomish (declares onConnect) and Talker (declares onmessage, a
        // case-variant that still overrides) are `true`.
        self::assertStringContainsString('"websocket": false', self::atomEntry($module, 'Plain'));
        self::assertStringContainsString('"websocket": true', self::atomEntry($module, 'Roomish'));
        self::assertStringContainsString('"websocket": true', self::atomEntry($module, 'Talker'));

        // Belt and braces on the collapse specifically: the only provable
        // `false` in this app is Plain's. A second one would mean Subroom's
        // omission was manufactured back into a claim.
        self::assertSame(
            1,
            substr_count($module, '"websocket": false'),
            'exactly one Atom (Plain) may carry a proven websocket:false; a second means an omitted key was collapsed',
        );

        self::rmrf($outDir);
    }

    /**
     * The `{ ... }` block for one Atom entry in the generated bundle's
     * `manifest.atoms` map. Atom values contain no nested objects (class/file
     * are strings, migrations is an array of string paths), so a single
     * brace-free capture is exact.
     */
    private static function atomEntry(string $module, string $type): string
    {
        self::assertSame(
            1,
            preg_match('/"' . preg_quote($type, '/') . '": \{([^{}]*)\}/', $module, $m),
            "atom {$type} should appear exactly once in the bridge output",
        );

        return $m[1];
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
