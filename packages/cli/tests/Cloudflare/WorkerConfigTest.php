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
            [
                'ATOMS_SHARED_SECRET',
                'ATOMS_SHARED_SECRET_PREVIOUS',
                'ATOMS_APP_KEY',
                'ATOMS_CALLBACK_SIGNING_KEY',
                'ATOMS_CONFIG_ENV_KEYS',
                'ATOMS_CONFIG_ENV_DENY_KEYS',
            ],
            $config->configEnvDenyKeys,
        );
        self::assertNull($config->source);
    }

    /**
     * `ATOMS_SHARED_SECRET` and `ATOMS_SHARED_SECRET_PREVIOUS` are the root of
     * the app <-> Worker boundary; `ATOMS_APP_KEY` and
     * `ATOMS_CALLBACK_SIGNING_KEY` are tombstones for the two secrets they
     * replaced. All four must be refused under any prefix, not just the one a
     * blank deny list happens to collide with.
     */
    public function testTheSharedSecretFamilyAndLegacyTombstonesAreAlwaysDenied(): void
    {
        $config = WorkerConfig::fromWorkerDir($this->freshDir());

        foreach (['ATOMS_SHARED_SECRET', 'ATOMS_SHARED_SECRET_PREVIOUS', 'ATOMS_APP_KEY', 'ATOMS_CALLBACK_SIGNING_KEY'] as $name) {
            self::assertFalse($config->isReadable($name), "{$name} must never be readable from Atom code");
            self::assertNotNull($config->unreadableReason($name), "{$name} must carry a refusal reason");
        }
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

    /**
     * A config that exists but will not parse must be loud. Falling back to the
     * default prefix would hand back exactly the silent breakage this class
     * removes — and a BOM, which Wrangler itself tolerates, is enough to
     * trigger it.
     */
    public function testUnparseableConfigIsReportedRatherThanDefaulted(): void
    {
        $config = WorkerConfig::fromWorkerDir($this->workerDir('{ this is not json at all '));

        self::assertNotNull($config->parseError);

        $bom = WorkerConfig::fromWorkerDir(
            $this->workerDir("\u{FEFF}" . '{"vars":{"ATOMS_CONFIG_ENV_PREFIX":"MYAPP_"}}'),
        );
        self::assertTrue(
            $bom->parseError !== null || $bom->configEnvPrefix === 'MYAPP_',
            'a BOM must either parse correctly or be reported — never silently yield the default prefix',
        );
    }

    /**
     * `config.js` falls back to the DEFAULT deny list when the variable is
     * blank, so modelling blank as "deny nothing" would let the CLI bless a
     * write to ATOMS_SHARED_SECRET or ATOMS_APP_KEY.
     */
    public function testABlankDenyListMeansTheDefaultsNotAnEmptyList(): void
    {
        foreach (['', ' ', "\u{00A0}"] as $blank) {
            // The prefix matters to the scenario: under ATOMS_, the keys
            // "shared secret" and "app key" land exactly on ATOMS_SHARED_SECRET
            // and ATOMS_APP_KEY. Under the default prefix they land on
            // ATOMS_CONFIG_SHARED_SECRET etc., which are perfectly ordinary
            // config names and rightly not refused.
            $config = WorkerConfig::fromWorkerDir($this->workerDir(json_encode([
                'vars' => [
                    'ATOMS_CONFIG_ENV_PREFIX' => 'ATOMS_',
                    'ATOMS_CONFIG_ENV_DENY_KEYS' => $blank,
                ],
            ], JSON_THROW_ON_ERROR)));

            self::assertSame(WorkerConfig::DEFAULT_DENY_KEYS, $config->configEnvDenyKeys);

            self::assertFalse($config->isReadable('ATOMS_SHARED_SECRET'));
            self::assertSame('ATOMS_SHARED_SECRET', $config->workerNameFor('shared secret'));
            self::assertNotNull(
                $config->keyRefusalReason('shared secret'),
                'writing the shared secret must be refused, not reported as a stored config value',
            );

            self::assertFalse($config->isReadable('ATOMS_APP_KEY'));
            self::assertSame('ATOMS_APP_KEY', $config->workerNameFor('app key'));
            self::assertNotNull(
                $config->keyRefusalReason('app key'),
                'the legacy app-key tombstone must still be refused',
            );
        }
    }

    /**
     * `secrets:set ATOMS_SHARED_SECRET v` would otherwise store the root
     * secret under ATOMS_CONFIG_ATOMS_SHARED_SECRET — a name outside the deny
     * list and therefore readable via `$this->config()`. The literal key must
     * be refused before it is ever prefixed, whatever case it arrives in.
     */
    public function testCredentialKeyNamesAreRefusedBeforePrefixing(): void
    {
        $config = WorkerConfig::fromWorkerDir($this->freshDir());

        foreach (['ATOMS_SHARED_SECRET', 'ATOMS_SHARED_SECRET_PREVIOUS', 'ATOMS_APP_KEY', 'ATOMS_CALLBACK_SIGNING_KEY'] as $name) {
            foreach ([$name, strtolower($name), " {$name} "] as $variant) {
                self::assertNotNull(
                    $config->keyRefusalReason($variant),
                    "{$variant} must be refused as a secrets:set key",
                );
            }
        }

        self::assertNull(
            $config->keyRefusalReason('PAYMENTS_API_KEY'),
            'an ordinary key must not be caught by the credential-name guard',
        );
    }

    /**
     * `config.js` requires `typeof v === 'string'`; a JSON number in `vars` is
     * ignored there, so coercing it here would compute a prefix nothing uses.
     */
    public function testNonStringVarsAreIgnoredExactlyAsTheWorkerIgnoresThem(): void
    {
        foreach (['{"vars":{"ATOMS_CONFIG_ENV_PREFIX":5}}', '{"vars":{"ATOMS_CONFIG_ENV_PREFIX":true}}'] as $json) {
            self::assertSame('ATOMS_CONFIG_', WorkerConfig::fromWorkerDir($this->workerDir($json))->configEnvPrefix);
        }
    }

    /**
     * Carrying the prefix is not enough: no key normalizes onto
     * `ATOMS_CONFIG_foo` or `ATOMS_CONFIG_A__B`, so the Worker resolves both to
     * null and neither may be reported readable.
     */
    public function testPrefixCarriersThatNoKeyNormalizesOntoAreNotReadable(): void
    {
        $config = WorkerConfig::fromWorkerDir($this->workerDir('{"vars":{}}'));

        self::assertFalse($config->isReadable('ATOMS_CONFIG_foo'));
        self::assertFalse($config->isReadable('ATOMS_CONFIG_A__B'));
        self::assertTrue($config->isReadable('ATOMS_CONFIG_A_B'));
    }

    /**
     * PHP uppercases byte-wise, the Worker's JavaScript uppercases by Unicode:
     * "straße" is STRA_E here and STRASSE there. Refuse rather than store a
     * name that will never be read.
     */
    public function testNonAsciiKeysAreRefusedBecauseTheTwoLanguagesDisagree(): void
    {
        $config = WorkerConfig::fromWorkerDir($this->workerDir('{"vars":{}}'));

        foreach (['straße', "\u{FB01}le", "\u{0131}d"] as $key) {
            self::assertNotNull($config->keyRefusalReason($key), "{$key} must be refused");
        }

        self::assertNull($config->keyRefusalReason('PAYMENTS_API_KEY'));
        self::assertNull($config->keyRefusalReason('payments.api.key'));
    }

    /**
     * A comma before a brace inside a string value is data, not a trailing
     * comma. A hand-written stripper got this wrong by rewriting the whole
     * document; the parser must not.
     */
    public function testACommaBeforeABraceInsideAStringIsNotATrailingComma(): void
    {
        $dir = $this->workerDir('{"vars":{"ATOMS_CONFIG_ENV_KEYS":"A, ]","ATOMS_CONFIG_ENV_PREFIX":"P_"}}');

        $config = WorkerConfig::fromWorkerDir($dir);

        self::assertSame('P_', $config->configEnvPrefix);
        self::assertSame(['A', ']'], $config->configEnvKeys, 'the Worker splits this value on commas; so must we');
    }

    public function testTomlReadsOnlyTheVarsTableAndBothStringForms(): void
    {
        $dir = $this->freshDir();

        // A per-environment table is not what `atoms deploy --name` deploys.
        file_put_contents($dir . '/wrangler.toml', "name = 'w'
[env.staging.vars]
ATOMS_CONFIG_ENV_PREFIX = \"STAGING_\"
");
        self::assertSame('ATOMS_CONFIG_', WorkerConfig::fromWorkerDir($dir)->configEnvPrefix);

        // TOML literal strings are single-quoted.
        file_put_contents($dir . '/wrangler.toml', "[vars]
ATOMS_CONFIG_ENV_PREFIX = 'MYAPP_'
");
        self::assertSame('MYAPP_', WorkerConfig::fromWorkerDir($dir)->configEnvPrefix);

        // Inline-table syntax is not understood, and says so.
        file_put_contents($dir . '/wrangler.toml', "vars = { ATOMS_CONFIG_ENV_PREFIX = \"X_\" }
");
        self::assertNotNull(WorkerConfig::fromWorkerDir($dir)->parseError);
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
