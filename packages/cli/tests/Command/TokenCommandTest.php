<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Command\TokenCommand;
use Atoms\Cli\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `atoms token` prints the bearer derived from ATOMS_SHARED_SECRET
 * (docs/shared-secret.md) — never the secret itself.
 */
final class TokenCommandTest extends TestCase
{
    /** The reference vector: bytes 0x00..0x1f, base64-encoded. */
    private const TEST_SECRET = 'AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    /** HKDF('sha256', <bytes 0x00..0x1f>, 32, 'atoms/bearer/v1', ''), base64. */
    private const EXPECTED_BEARER = 'Dx6RY9LS43pOQhM4PMdaUWx3lk9mfyiiJZFfJtvl9E0=';

    protected function tearDown(): void
    {
        putenv('ATOMS_SHARED_SECRET');
        parent::tearDown();
    }

    public function testPrintsTheReferenceVectorFromTheEnvironmentSecretAndNothingElse(): void
    {
        putenv('ATOMS_SHARED_SECRET=' . self::TEST_SECRET);

        $tester = new CommandTester(new TokenCommand());
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertSame(self::EXPECTED_BEARER . "\n", $tester->getDisplay());
    }

    public function testTheEnvironmentSecretIsNeverPrinted(): void
    {
        putenv('ATOMS_SHARED_SECRET=' . self::TEST_SECRET);

        $tester = new CommandTester(new TokenCommand());
        $tester->execute([]);

        self::assertStringNotContainsString(self::TEST_SECRET, $tester->getDisplay());
    }

    public function testFallsBackToTheDevVarsLineWhenNoEnvironmentSecretIsSet(): void
    {
        putenv('ATOMS_SHARED_SECRET');
        $dir = $this->freshDir();
        file_put_contents($dir . '/.dev.vars', "ATOMS_CALLBACK_URL=http://example.com\nATOMS_SHARED_SECRET=" . self::TEST_SECRET . "\n");

        $tester = new CommandTester(new TokenCommand());
        $exit = $tester->execute(['--worker-dir' => $dir]);

        self::assertSame(0, $exit, $tester->getDisplay());
        self::assertSame(self::EXPECTED_BEARER . "\n", $tester->getDisplay());
    }

    public function testTheEnvironmentSecretTakesPrecedenceOverDevVars(): void
    {
        putenv('ATOMS_SHARED_SECRET=' . self::TEST_SECRET);
        $dir = $this->freshDir();
        // A different, otherwise-valid secret in .dev.vars; the environment
        // variable must win.
        file_put_contents($dir . '/.dev.vars', 'ATOMS_SHARED_SECRET=' . base64_encode(str_repeat("\xff", 32)) . "\n");

        $tester = new CommandTester(new TokenCommand());
        $tester->execute(['--worker-dir' => $dir]);

        self::assertSame(self::EXPECTED_BEARER . "\n", $tester->getDisplay());
    }

    public function testFailsWithTheCatalogCodeWhenNoSecretIsConfiguredAnywhere(): void
    {
        putenv('ATOMS_SHARED_SECRET');
        $dir = $this->freshDir();

        $tester = new CommandTester(new TokenCommand());
        $exit = $tester->execute(['--worker-dir' => $dir]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('ATOMS-E105', $tester->getDisplay());
    }

    /**
     * No --worker-dir and no atoms.json findable from --root must fail with
     * the plain "no secret configured" error rather than an unrelated
     * atoms.json-not-found one leaking through.
     */
    public function testFailsCleanlyWhenNeitherAnEnvironmentSecretNorAWorkerDirIsResolvable(): void
    {
        putenv('ATOMS_SHARED_SECRET');

        $tester = new CommandTester(new TokenCommand());
        $exit = $tester->execute(['--root' => $this->freshDir()]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('ATOMS-E105', $tester->getDisplay());
    }

    public function testFailsWhenTheEnvironmentSecretIsNotValidBase64(): void
    {
        putenv('ATOMS_SHARED_SECRET=not-valid-base64!!');

        $tester = new CommandTester(new TokenCommand());
        $exit = $tester->execute([]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('ATOMS-E105', $tester->getDisplay());
    }

    public function testFailsWhenTheDecodedSecretIsNotExactlyThirtyTwoBytes(): void
    {
        putenv('ATOMS_SHARED_SECRET=' . base64_encode('too short'));

        $tester = new CommandTester(new TokenCommand());
        $exit = $tester->execute([]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('ATOMS-E105', $tester->getDisplay());
    }

    public function testWhitespaceAroundTheSecretIsTrimmedBeforeDecoding(): void
    {
        putenv('ATOMS_SHARED_SECRET= ' . self::TEST_SECRET . " \n");

        $tester = new CommandTester(new TokenCommand());
        $tester->execute([]);

        self::assertSame(self::EXPECTED_BEARER . "\n", $tester->getDisplay());
    }
    /**
     * The `.dev.vars` fallback must not depend on an environment name.
     *
     * This command used to take `--env`, defaulting to `staging`, purely to
     * locate the Worker directory through `CloudflareTarget::resolve()` — but
     * the Worker directory is a committed convention shared by every
     * environment, so the option never changed the answer. What it did change
     * was failure: a project whose environments are named anything but
     * `staging` threw ATOMS-E070 inside that lookup, the fallback was skipped,
     * and `atoms token` reported ATOMS-E105 with a perfectly good secret
     * sitting in `atoms-worker/.dev.vars`.
     */
    public function testFallsBackToDevVarsWhateverTheProjectsEnvironmentsAreCalled(): void
    {
        putenv('ATOMS_SHARED_SECRET');
        $root = $this->freshDir();
        file_put_contents($root . '/atoms.json', json_encode([
            'project' => 'acme',
            'paths' => ['atoms' => 'app/Atoms'],
            // Deliberately no environment named `staging`.
            'environments' => ['live' => ['worker_name' => 'acme']],
        ], JSON_THROW_ON_ERROR));
        mkdir($root . '/atoms-worker');
        file_put_contents($root . '/atoms-worker/.dev.vars', 'ATOMS_SHARED_SECRET=' . self::TEST_SECRET . "\n");

        $tester = new CommandTester(new TokenCommand());
        $exit = $tester->execute(['--root' => $root]);

        self::assertSame(0, $exit, $tester->getDisplay());
        self::assertSame(self::EXPECTED_BEARER . "\n", $tester->getDisplay());
    }

    /**
     * And the option is gone rather than silently ignored: `--env` never
     * affected the bearer, which is derived from ATOMS_SHARED_SECRET alone.
     */
    public function testThereIsNoEnvironmentOption(): void
    {
        self::assertFalse((new TokenCommand())->getDefinition()->hasOption('env'));
    }
}