<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Build;

use Atoms\Cli\Build\BundleFileSet;
use Atoms\Cli\Build\Discovery;
use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Tests\TestCase;

/**
 * F15: the file set owns its bytes. Each source file is read exactly once per
 * file set, so every fingerprint (treeHash → scoper_prefix) and every byte
 * BundleWriter emits describes the same snapshot of the tree — and an
 * unreadable file is a loud failure, not a silent empty hash contribution.
 */
final class BundleFileSetTest extends TestCase
{
    public function testScoperPrefixIsStableWhenASourceFileChangesBetweenReads(): void
    {
        $config = $this->copiedApp();
        $bundleFiles = $this->collect($config);
        $before = $bundleFiles->scoperPrefix();

        // Mutate a bundled file after the first fingerprint was taken. The
        // prefix must not drift: it describes the snapshot the set already
        // read, not whatever is on disk at the moment of the second call.
        $atom = $config->rootDir . '/app/Atoms/GameRoom.php';
        $this->assertFileExists($atom);
        file_put_contents($atom, (string) file_get_contents($atom) . "\n// mutated mid-build\n");

        self::assertSame($before, $bundleFiles->scoperPrefix());
    }

    public function testAFreshValidationSeesChangedContent(): void
    {
        $config = $this->copiedApp();
        $before = $this->collect($config)->scoperPrefix();

        $atom = $config->rootDir . '/app/Atoms/Shared/PlayerSnapshot.php';
        $this->assertFileExists($atom);
        file_put_contents($atom, (string) file_get_contents($atom) . "\n// changed on disk\n");

        // The stability above must come from memoization, not from content
        // being excluded from the hash: a new validation of the changed tree
        // produces a different prefix.
        $after = $this->collect($config)->scoperPrefix();

        self::assertNotSame($before, $after);
    }

    public function testUnreadableBundleFileThrowsInsteadOfHashingAsEmpty(): void
    {
        $config = $this->copiedApp();
        $bundleFiles = $this->collect($config);

        unlink($config->rootDir . '/app/Atoms/GameRoom.php');

        try {
            $bundleFiles->treeHash();
            self::fail('An unreadable bundle file must abort, not hash as empty.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('GameRoom.php', $e->getMessage());
        }
    }

    private function copiedApp(): AtomsJson
    {
        return AtomsJson::load($this->tempCopy('sample-app') . '/atoms.json');
    }

    /**
     * The real pipeline's collection step: Discovery + BundleFileSet::collect,
     * so these tests exercise the same path Validator and Builder use.
     */
    private function collect(AtomsJson $config): BundleFileSet
    {
        return BundleFileSet::collect($config, (new Discovery())->discover($config));
    }
}
