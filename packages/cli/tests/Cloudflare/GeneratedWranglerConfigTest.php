<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Cloudflare;

use Atoms\Cli\Cloudflare\CloudflareTarget;
use Atoms\Cli\Cloudflare\GeneratedWranglerConfig;
use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Tests\TestCase;
use Atoms\Errors\AtomsError;

final class GeneratedWranglerConfigTest extends TestCase
{
    /**
     * A target for the sample app's production environment, with the given
     * routing added, pointed at a fresh Worker directory holding $wrangler.
     *
     * @param list<string> $customDomains
     */
    private function target(string $wrangler, array $customDomains = [], string $file = 'wrangler.jsonc'): CloudflareTarget
    {
        $root = $this->tempCopy('sample-app');
        $config = json_decode((string) file_get_contents($root . '/atoms.json'), true);
        $config['environments']['production']['custom_domains'] = $customDomains;
        file_put_contents($root . '/atoms.json', json_encode($config, JSON_THROW_ON_ERROR));

        $dir = $this->freshDir();
        file_put_contents($dir . '/' . $file, $wrangler);

        return CloudflareTarget::resolve(AtomsJson::load($root . '/atoms.json'), 'production', 'token', $dir);
    }

    public function testTheEnvironmentIsAppliedOverTheUsersFile(): void
    {
        $target = $this->target(<<<'JSONC'
            {
              // comments and trailing commas are Wrangler's own format
              "name": "atoms-worker",
              "main": "src/index.js",
              "compatibility_date": "2026-08-01",
              "vars": { "ATOMS_LOG_LEVEL": "debug", "ATOMS_CALLBACK_URL": "https://stale.example.com" },
              "observability": { "enabled": true },
            }
            JSONC, ['atoms.example.com', 'www.example.com']);

        $generated = GeneratedWranglerConfig::generate($target, ['ATOMS_CALLBACK_URL' => 'https://acme.example.com']);

        self::assertSame('acme-games', $generated->document['name']);
        // Every hostname in the object form that makes it a custom domain;
        // no bare route pattern is ever written.
        self::assertSame(
            [
                ['pattern' => 'atoms.example.com', 'custom_domain' => true],
                ['pattern' => 'www.example.com', 'custom_domain' => true],
            ],
            $generated->document['routes'],
        );
        // The file's vars travel; a runtime var of the same name wins.
        self::assertSame(
            ['ATOMS_LOG_LEVEL' => 'debug', 'ATOMS_CALLBACK_URL' => 'https://acme.example.com'],
            $generated->document['vars'],
        );
        self::assertSame(['enabled' => true], $generated->document['observability']);
        self::assertFalse($generated->declaresRouting);
        self::assertStringEndsWith('/wrangler.jsonc', $generated->source);
    }

    public function testNoRoutingAndNoVarsLeaveBothKeysOut(): void
    {
        $generated = GeneratedWranglerConfig::generate($this->target('{"main": "src/index.js"}'), []);

        self::assertArrayNotHasKey('routes', $generated->document);
        // `vars: []` would encode as a JSON array, which is not a vars object.
        self::assertArrayNotHasKey('vars', $generated->document);
    }

    /**
     * A generated config targets one environment by construction, so
     * Wrangler's own `env` blocks have nothing to select and are dropped —
     * and the routing the user's file declares at the top level is replaced
     * by the environment's, which here is none.
     */
    public function testEnvBlocksAndTopLevelRoutingAreDropped(): void
    {
        $target = $this->target(json_encode([
            'main' => 'src/index.js',
            'route' => 'old.example.com/*',
            'routes' => [['pattern' => 'atoms.example.com', 'custom_domain' => true]],
            'env' => ['staging' => ['vars' => ['X' => '1']]],
        ], JSON_THROW_ON_ERROR));

        $generated = GeneratedWranglerConfig::generate($target, []);

        self::assertArrayNotHasKey('env', $generated->document);
        self::assertArrayNotHasKey('route', $generated->document);
        self::assertArrayNotHasKey('routes', $generated->document);
        self::assertTrue($generated->declaresRouting);
    }

    /**
     * Wrangler resolves these keys against the directory of the config file
     * that declares them, and the generated file lives two levels below the
     * Worker directory.
     */
    public function testRelativePathsAreReanchoredAndAbsoluteOnesLeftAlone(): void
    {
        $target = $this->target(json_encode([
            '$schema' => 'node_modules/wrangler/config-schema.json',
            'main' => 'src/index.js',
            'tsconfig' => '/abs/tsconfig.json',
            'assets' => ['directory' => 'public', 'binding' => 'ASSETS'],
            'site' => ['bucket' => './static'],
            'rules' => [['type' => 'CompiledWasm', 'globs' => ['**/*.wasm']]],
        ], JSON_THROW_ON_ERROR));

        $document = GeneratedWranglerConfig::generate($target, [])->document;

        self::assertSame('../../node_modules/wrangler/config-schema.json', $document['$schema']);
        self::assertSame('../../src/index.js', $document['main']);
        self::assertSame('/abs/tsconfig.json', $document['tsconfig']);
        self::assertSame(['directory' => '../../public', 'binding' => 'ASSETS'], $document['assets']);
        self::assertSame(['bucket' => '../.././static'], $document['site']);
        // Globs are matched against module paths, not the config directory.
        self::assertSame([['type' => 'CompiledWasm', 'globs' => ['**/*.wasm']]], $document['rules']);
    }

    public function testASchemaUrlIsNotAPath(): void
    {
        $target = $this->target('{"$schema": "https://example.com/schema.json", "main": "src/index.js"}');

        self::assertSame('https://example.com/schema.json', GeneratedWranglerConfig::generate($target, [])->document['$schema']);
    }

    public function testWriteProducesTheRedirectAndTheConfigAndRemoveTakesOnlyThoseAway(): void
    {
        $target = $this->target('{"main": "src/index.js"}');
        $generated = GeneratedWranglerConfig::generate($target, ['ATOMS_DEBUG_ENDPOINTS' => '1']);

        $path = $generated->write();

        self::assertSame($target->workerDir . '/.wrangler/deploy/wrangler.json', $path);
        self::assertSame(
            ['configPath' => 'wrangler.json'],
            json_decode((string) file_get_contents($target->workerDir . '/.wrangler/deploy/config.json'), true),
        );
        $written = json_decode((string) file_get_contents($path), true);
        self::assertSame('acme-games', $written['name']);
        self::assertSame(['ATOMS_DEBUG_ENDPOINTS' => '1'], $written['vars']);

        // Wrangler's other state in that directory is not ours to delete.
        file_put_contents($target->workerDir . '/.wrangler/deploy/other.txt', 'x');
        $generated->remove();
        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist($target->workerDir . '/.wrangler/deploy/config.json');
        self::assertFileExists($target->workerDir . '/.wrangler/deploy/other.txt');

        // Removing twice is fine.
        $generated->remove();
    }

    public function testWriteIsDeterministic(): void
    {
        $target = $this->target('{"main": "src/index.js", "vars": {"B": "2", "A": "1"}}', ['a.example.com/*']);
        $generated = GeneratedWranglerConfig::generate($target, ['Z' => 'z']);

        $first = file_get_contents($generated->write());
        $second = file_get_contents($generated->write());

        self::assertSame($first, $second);
        self::assertStringEndsWith("\n", (string) $first);
    }

    public function testAWranglerJsonIsReadWhenThereIsNoJsonc(): void
    {
        $target = $this->target('{"main": "src/index.js"}', file: 'wrangler.json');

        self::assertStringEndsWith('/wrangler.json', GeneratedWranglerConfig::generate($target, [])->source);
    }

    public function testAnUnparseableFileIsE076NotADefault(): void
    {
        $target = $this->target('{"main": ');

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E076.*could not parse/');
        GeneratedWranglerConfig::generate($target, []);
    }

    public function testANonObjectFileIsE076(): void
    {
        $target = $this->target('"just a string"');

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E076.*not a JSON object/');
        GeneratedWranglerConfig::generate($target, []);
    }

    public function testAWranglerTomlIsRefusedWithTheConversionNamed(): void
    {
        $target = $this->target("name = \"w\"\n", file: 'wrangler.toml');

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E076.*convert it to wrangler\.jsonc/');
        GeneratedWranglerConfig::generate($target, []);
    }

    public function testAMissingFileIsE076(): void
    {
        $target = CloudflareTarget::resolve($this->sampleApp(), 'production', 'token', $this->freshDir());

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessageMatches('/ATOMS-E076.*no wrangler\.jsonc or wrangler\.json/');
        GeneratedWranglerConfig::generate($target, []);
    }
}
