<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Deployment;

use Atoms\Client\Deployment\CallerEnvironment;
use PHPUnit\Framework\TestCase;

final class CallerEnvironmentTest extends TestCase
{
    /** @var array<string, string> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Composer's autoload bootstrap captured the real environment when
        // this test run started. Put it back after each case.
        $this->captured = CallerEnvironment::all() ?? [];
        putenv('ATOMS_TEST_CALLER');
        unset($_ENV['ATOMS_TEST_CALLER']);
    }

    protected function tearDown(): void
    {
        CallerEnvironment::captureFor($this->captured);
        putenv('ATOMS_TEST_CALLER');
        unset($_ENV['ATOMS_TEST_CALLER']);
        parent::tearDown();
    }

    /**
     * The `files` autoload entry runs while vendor/autoload.php is still being
     * included, so by the time any test body runs there is already a snapshot.
     * If this fails, the entry has been dropped from composer.json and every
     * framework wrapper has quietly gone back to inheriting a bootstrapped
     * environment.
     */
    public function testComposerAutoloadCapturedTheEnvironmentBeforeAnythingElseRan(): void
    {
        self::assertTrue(CallerEnvironment::isCaptured());
        self::assertArrayHasKey('PATH', CallerEnvironment::all() ?? []);
    }

    public function testAValueTheCallerSuppliedSurvivesIntoTheChild(): void
    {
        CallerEnvironment::captureFor(['PATH' => '/usr/bin', 'ATOMS_CALLBACK_URL' => 'https://ci.example/cb']);

        self::assertSame('https://ci.example/cb', CallerEnvironment::restoration()['ATOMS_CALLBACK_URL']);
    }

    /**
     * The whole point: a name a framework bootstrap added after the snapshot
     * is removed rather than passed on. `false` is how Symfony's Process
     * spells "unset this variable in the child".
     */
    public function testAValueAddedAfterTheSnapshotIsRemoved(): void
    {
        CallerEnvironment::captureFor(['PATH' => '/usr/bin']);
        putenv('ATOMS_TEST_CALLER=added-by-bootstrap');

        $restoration = CallerEnvironment::restoration();

        self::assertArrayHasKey('ATOMS_TEST_CALLER', $restoration);
        self::assertFalse($restoration['ATOMS_TEST_CALLER']);
        self::assertSame('/usr/bin', $restoration['PATH']);
    }

    /**
     * A dotenv loader writing only to $_ENV is the same problem: Symfony's
     * Process merges $_ENV over getenv() when it builds a child's
     * environment, so a name that appears there must be removed too.
     */
    public function testAValueAddedToTheSuperglobalAloneIsAlsoRemoved(): void
    {
        CallerEnvironment::captureFor(['PATH' => '/usr/bin']);
        $_ENV['ATOMS_TEST_CALLER'] = 'added-by-bootstrap';

        self::assertFalse(CallerEnvironment::restoration()['ATOMS_TEST_CALLER']);
    }

    /**
     * An overwriting loader must not win either: restoration puts the caller's
     * value back rather than trusting whatever is there now.
     */
    public function testAnOverwrittenValueIsPutBack(): void
    {
        CallerEnvironment::captureFor(['ATOMS_TEST_CALLER' => 'from-the-caller']);
        putenv('ATOMS_TEST_CALLER=overwritten-by-bootstrap');

        self::assertSame('from-the-caller', CallerEnvironment::restoration()['ATOMS_TEST_CALLER']);
    }

    /**
     * With no snapshot there is nothing to correct, and a child that would
     * have inherited normally still does. That is the standalone
     * `vendor/bin/atoms` case, where the process environment already *is* the
     * caller's.
     */
    public function testNoSnapshotMeansNoInterference(): void
    {
        CallerEnvironment::forget();

        self::assertSame([], CallerEnvironment::restoration());
        self::assertNull(CallerEnvironment::all());
    }

    public function testTheFirstCaptureWins(): void
    {
        CallerEnvironment::forget();
        putenv('ATOMS_TEST_CALLER=first');
        CallerEnvironment::capture();
        putenv('ATOMS_TEST_CALLER=second');
        CallerEnvironment::capture();

        self::assertSame('first', (CallerEnvironment::all() ?? [])['ATOMS_TEST_CALLER'] ?? null);
    }
}
