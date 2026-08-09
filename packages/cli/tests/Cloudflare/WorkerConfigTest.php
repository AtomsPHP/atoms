<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Cloudflare;

use Atoms\Cli\Cloudflare\SecretName;
use Atoms\Cli\Cloudflare\WorkerConfig;
use Atoms\Cli\Tests\TestCase;

/**
 * The CLI and `worker/src/bridge.js` must agree about which Worker variable
 * answers `$this->config('K')`. They are separate implementations in separate
 * languages, so every assertion here is really a claim about that agreement.
 */
final class WorkerConfigTest extends TestCase
{
    private function workerDir(string $wranglerJsonc): string
    {
        $dir = $this->freshDir();
        file_put_contents($dir . '/wrangler.jsonc', $wranglerJsonc);

        return $dir;
    }

    public function testDefaultsMatchWorkerConfigJsWhenNothingIsConfigured(): void
    {
        $config = WorkerConfig::fromWorkerDir($this->freshDir());

        self::assertSame('ATOMS_CONFIG_', $config->configEnvPrefix);
        self::assertSame([], $config->configEnvKeys);
        self::assertSame(
            ['ATOMS_APP_KEY', 'ATOMS_CONFIG_ENV_KEYS', 'ATOMS_CONFIG_ENV_DENY_KEYS'],
            $config->configEnvDenyKeys,
        );
        self::assertNull($config->source);
    }

    /**
     * The bug this whole class exists for: a Worker configured with a custom
     * prefix, where a hardcoded `ATOMS_CONFIG_` writes a secret that reads back
     * as null forever, with no error on either side.
     */
    public function testACustomPrefixChangesTheWorkerVariableName(): void
    {
        $dir = $this->workerDir(<<<'JSONC'
            {
              "name": "w",
              "vars": { "ATOMS_CONFIG_ENV_PREFIX": "MYAPP_" }
            }
            JSONC);

        $config = WorkerConfig::fromWorkerDir($dir);

        self::assertSame('MYAPP_', $config->configEnvPrefix);
        self::assertSame('MYAPP_PAYMENTS_API_KEY', $config->workerNameFor('PAYMENTS_API_KEY'));
        self::assertTrue($config->isReadable('MYAPP_PAYMENTS_API_KEY'));
        self::assertFalse(
            $config->isReadable('ATOMS_CONFIG_PAYMENTS_API_KEY'),
            'the default prefix must stop being readable once it is overridden',
        );
    }

    public function testParsesTheRepositoriesOwnWranglerConfig(): void
    {
        // The real file: block comment before `{`, `//` comments between keys,
        // and a `$schema` key. If the stripper cannot handle this one, the
        // feature is useless for the project that ships it.
        $config = WorkerConfig::fromWorkerDir(\dirname(__DIR__, 4) . '/cloudflare/worker');

        self::assertNotNull($config->source);
        self::assertSame('ATOMS_CONFIG_', $config->configEnvPrefix, 'the repo config sets no prefix, so the default holds');
    }

    public function testCommentsAndTrailingCommasDoNotDefeatTheParser(): void
    {
        $dir = $this->workerDir(<<<'JSONC'
            // leading line comment
            {
              /* block
                 comment */
              "name": "w", // trailing line comment
              "vars": {
                "ATOMS_CONFIG_ENV_PREFIX": "P_",
                "ATOMS_CONFIG_ENV_KEYS": "EXACT_ONE, EXACT_TWO",
              },
            }
            JSONC);

        $config = WorkerConfig::fromWorkerDir($dir);

        self::assertSame('P_', $config->configEnvPrefix);
        self::assertSame(['EXACT_ONE', 'EXACT_TWO'], $config->configEnvKeys);
    }

    /**
     * A `//` inside a string is not a comment. A URL in `vars` is the ordinary
     * way to hit this.
     */
    public function testASlashSlashInsideAStringIsNotAComment(): void
    {
        $dir = $this->workerDir(<<<'JSONC'
            {
              "vars": {
                "ATOMS_CONFIG_ENV_PREFIX": "P_",
                "SOME_URL": "https://example.com/a//b"
              }
            }
            JSONC);

        self::assertSame('P_', WorkerConfig::fromWorkerDir($dir)->configEnvPrefix);
    }

    public function testUnparseableConfigFallsBackToTheDocumentedDefaults(): void
    {
        $dir = $this->workerDir('{ this is not json at all ');

        $config = WorkerConfig::fromWorkerDir($dir);

        self::assertSame('ATOMS_CONFIG_', $config->configEnvPrefix);
    }

    public function testExactKeysAreReadableWithoutThePrefix(): void
    {
        $dir = $this->workerDir('{"vars":{"ATOMS_CONFIG_ENV_KEYS":"LEGACY_NAME"}}');

        $config = WorkerConfig::fromWorkerDir($dir);

        self::assertTrue($config->isReadable('LEGACY_NAME'));
        self::assertFalse($config->isReadable('UNLISTED_NAME'));
    }

    public function testTheDenyListWinsOverThePrefix(): void
    {
        $dir = $this->workerDir('{"vars":{"ATOMS_CONFIG_ENV_DENY_KEYS":"ATOMS_CONFIG_BANNED"}}');

        $config = WorkerConfig::fromWorkerDir($dir);

        self::assertFalse($config->isReadable('ATOMS_CONFIG_BANNED'));
        self::assertNotNull($config->unreadableReason('ATOMS_CONFIG_BANNED'));
        self::assertTrue($config->isReadable('ATOMS_CONFIG_ALLOWED'));
    }

    /**
     * `atoms secrets:set ENV_PREFIX` resolves to ATOMS_CONFIG_ENV_PREFIX, which
     * does not store a value — it changes how every other key resolves. Two of
     * the three are also on the default deny list, so they would read back as
     * null regardless.
     */
    public function testTheAllowlistControlVariablesAreRefused(): void
    {
        $config = WorkerConfig::fromWorkerDir($this->freshDir());

        foreach (['ENV_PREFIX', 'ENV_KEYS', 'ENV_DENY_KEYS'] as $key) {
            $name = $config->workerNameFor($key);
            self::assertNotNull(
                $config->unreadableReason($name),
                "{$name} must be refused rather than silently stored",
            );
        }
    }

    public function testNameMappingIsSymmetricUnderACustomPrefix(): void
    {
        self::assertSame('P_A_B', SecretName::toWorker('a.b', 'P_'));
        self::assertSame('A_B', SecretName::toKey('P_A_B', 'P_'));
        self::assertNull(SecretName::toKey('ATOMS_CONFIG_A', 'P_'));
    }
}
