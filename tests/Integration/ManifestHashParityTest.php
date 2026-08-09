<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration;

use Atoms\Cli\Build\ManifestHash;
use Atoms\Cli\Build\Validator;
use Atoms\Cli\Config\AtomsJson;
use Atoms\Client\Manifest\ManifestLoader;
use PHPUnit\Framework\TestCase;

/**
 * Cross-package contract test: the manifest hash the CLI stamps on a build
 * MUST equal the hash atoms/client computes when it loads that manifest —
 * version-skew detection (ATOMS-E040/E041) depends on the two agreeing.
 * Both implement "canonical JSON" from docs/conventions.md independently;
 * this test is the referee.
 */
final class ManifestHashParityTest extends TestCase
{
    public function testCliAndClientComputeTheSameManifestHash(): void
    {
        $fixture = \dirname(__DIR__, 2) . '/packages/cli/tests/Fixtures/sample-app';
        self::assertDirectoryExists($fixture);

        $result = (new Validator())->validate(AtomsJson::locate($fixture));
        self::assertTrue($result->ok(), 'sample-app fixture must validate cleanly');

        $cliHash = ManifestHash::of($result->manifest);

        $manifest = (new ManifestLoader())->parse(
            json_encode($result->manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );

        self::assertSame($cliHash, $manifest->hash());
    }

    public function testHashIgnoresContentHashAndKeyOrder(): void
    {
        $fixture = \dirname(__DIR__, 2) . '/packages/cli/tests/Fixtures/sample-app';
        $result = (new Validator())->validate(AtomsJson::locate($fixture));

        $withContentHash = $result->manifest;
        $withContentHash['content_hash'] = str_repeat('ab', 32);
        $reversed = array_reverse($withContentHash, true);

        $clientHash = (new ManifestLoader())
            ->parse(json_encode($reversed, JSON_THROW_ON_ERROR))
            ->hash();

        self::assertSame(ManifestHash::of($result->manifest), $clientHash);
    }
}
