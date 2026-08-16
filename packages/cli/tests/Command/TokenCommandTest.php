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
}
