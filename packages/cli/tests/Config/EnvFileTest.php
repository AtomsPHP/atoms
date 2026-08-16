<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Config;

use Atoms\Cli\Config\EnvFile;
use Atoms\Cli\Tests\TestCase;

/**
 * The one dotenv parser the CLI uses for both the Worker's `.dev.vars` and the
 * app's `.env`. A value that changed shape moving between them would fail the
 * Worker's bearer check, so round-tripping is the property under test.
 */
final class EnvFileTest extends TestCase
{
    private function file(string $contents = ''): string
    {
        $path = $this->freshDir() . '/.env';
        if ($contents !== '') {
            file_put_contents($path, $contents);
        }

        return $path;
    }

    public function testReadsAPlainValue(): void
    {
        $path = $this->file("A=1\nKEY=value\nB=2\n");

        self::assertSame('value', EnvFile::read($path, 'KEY'));
    }

    public function testReadsAQuotedValueUnquoted(): void
    {
        $path = $this->file("KEY=\"quoted\"\nOTHER='single'\n");

        self::assertSame('quoted', EnvFile::read($path, 'KEY'));
        self::assertSame('single', EnvFile::read($path, 'OTHER'));
    }

    public function testMissingKeyAndMissingFileBothReadNull(): void
    {
        self::assertNull(EnvFile::read($this->file("A=1\n"), 'KEY'));
        self::assertNull(EnvFile::read($this->freshDir() . '/nope', 'KEY'));
    }

    /**
     * A key must not match inside another key's value, or a longer key that
     * ends with it — the pattern is anchored per line for exactly this.
     */
    public function testDoesNotMatchASubstringOfAnotherKey(): void
    {
        $path = $this->file("MY_KEY=wrong\nNOTE=KEY=alsowrong\nKEY=right\n");

        self::assertSame('right', EnvFile::read($path, 'KEY'));
    }

    public function testEmptyValueReadsNull(): void
    {
        self::assertNull(EnvFile::read($this->file("KEY=\n"), 'KEY'));
    }

    public function testWriteReplacesInPlacePreservingOrderAndComments(): void
    {
        $path = $this->file("# top\nA=1\nKEY=old\nB=2\n");

        self::assertFalse(EnvFile::write($path, 'KEY', 'new'));
        self::assertSame("# top\nA=1\nKEY=new\nB=2\n", (string) file_get_contents($path));
    }

    /** A rewrite lands where the line already sat, indentation included. */
    public function testWriteKeepsTheIndentationOfTheLineItReplaces(): void
    {
        $path = $this->file("A=1\n  KEY=old\nB=2\n");

        self::assertFalse(EnvFile::write($path, 'KEY', 'new'));
        self::assertSame("A=1\n  KEY=new\nB=2\n", (string) file_get_contents($path));
    }

    public function testWriteAppendsWithItsCommentWhenTheKeyIsAbsent(): void
    {
        $path = $this->file("A=1\n");

        self::assertFalse(EnvFile::write($path, 'KEY', 'v', 'why this is here'));
        self::assertSame("A=1\n\n# why this is here\nKEY=v\n", (string) file_get_contents($path));
    }

    public function testWriteAppendsANewlineWhenTheFileDoesNotEndWithOne(): void
    {
        $path = $this->file('A=1');

        EnvFile::write($path, 'KEY', 'v');
        self::assertSame("A=1\n\nKEY=v\n", (string) file_get_contents($path));
        self::assertSame('v', EnvFile::read($path, 'KEY'));
    }

    public function testWriteCreatesTheFileAtMode0600(): void
    {
        $path = $this->freshDir() . '/.env';

        self::assertTrue(EnvFile::write($path, 'KEY', 'v'));
        self::assertSame("KEY=v\n", (string) file_get_contents($path));
        self::assertSame(0600, fileperms($path) & 0777);
    }

    /**
     * `$` and `\` are backreference syntax in a preg_replace replacement, so a
     * value carrying either must survive an in-place replace literally.
     */
    public function testReplacementTreatsTheValueAsALiteral(): void
    {
        $path = $this->file("KEY=old\n");
        $value = 'a$1b\\2c$$d';

        EnvFile::write($path, 'KEY', $value);
        self::assertSame($value, EnvFile::read($path, 'KEY'));
    }

    public function testRoundTripsEveryByteOfABase64Secret(): void
    {
        $path = $this->file("A=1\n");
        $secret = base64_encode(random_bytes(32));

        EnvFile::write($path, 'KEY', $secret);
        self::assertSame($secret, EnvFile::read($path, 'KEY'));
    }
}
