<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Config;

use Atoms\Cli\Config\AtomsDotenv;
use Atoms\Cli\Tests\TestCase;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;

final class AtomsDotenvTest extends TestCase
{
    public function testAnAbsentFileContributesNothing(): void
    {
        $dotenv = AtomsDotenv::forTarget($this->freshDir(), 'production');

        self::assertNull($dotenv->path);
        self::assertNull($dotenv->value('ATOMS_CALLBACK_URL'));
    }

    /**
     * The target names the file, and nothing else does. There is no fallback
     * to the application's `.env`, to another target's file, or to a generic
     * `.env.atoms` — the whole point is that a local value cannot decide a
     * production deployment by accident.
     */
    public function testOnlyTheNamedTargetsFileIsRead(): void
    {
        $root = $this->freshDir();
        file_put_contents($root . '/.env', "ATOMS_CALLBACK_URL=http://localhost:8000/app\n");
        file_put_contents($root . '/.env.atoms', "ATOMS_CALLBACK_URL=http://generic.example/app\n");
        file_put_contents($root . '/.env.atoms.staging', "ATOMS_CALLBACK_URL=https://staging.example/app\n");
        file_put_contents($root . '/.env.atoms.production', "ATOMS_CALLBACK_URL=https://prod.example/app\n");

        self::assertSame(
            'https://prod.example/app',
            AtomsDotenv::forTarget($root, 'production')->value('ATOMS_CALLBACK_URL'),
        );
        self::assertSame(
            'https://staging.example/app',
            AtomsDotenv::forTarget($root, 'staging')->value('ATOMS_CALLBACK_URL'),
        );
        // A target with no file of its own falls back to nothing at all.
        self::assertNull(AtomsDotenv::forTarget($root, 'review')->value('ATOMS_CALLBACK_URL'));
    }

    public function testParsesTheSyntaxItDocuments(): void
    {
        $root = $this->freshDir();
        file_put_contents($root . '/.env.atoms.production', <<<'ENV'
            # a comment
            ATOMS_CALLBACK_URL=https://app.example.com/atoms/callback

              # an indented comment
            export CLOUDFLARE_ACCOUNT_ID = spaced-out
            SINGLE='literal $NOT_EXPANDED value'
            DOUBLE="a\tb\nc \"quoted\""
            TRAILING=value # not part of the value
            HASH=pa#ssword
            EMPTY=
            BLANK="   "
            ENV);

        $dotenv = AtomsDotenv::forTarget($root, 'production');

        self::assertSame('https://app.example.com/atoms/callback', $dotenv->value('ATOMS_CALLBACK_URL'));
        self::assertSame('spaced-out', $dotenv->value('CLOUDFLARE_ACCOUNT_ID'));
        // Single quotes are literal: no interpolation anywhere in this file.
        self::assertSame('literal $NOT_EXPANDED value', $dotenv->value('SINGLE'));
        self::assertSame("a\tb\nc \"quoted\"", $dotenv->value('DOUBLE'));
        self::assertSame('value', $dotenv->value('TRAILING'));
        // A `#` with no leading space belongs to the value — passwords have them.
        self::assertSame('pa#ssword', $dotenv->value('HASH'));
        // Blank means unset here exactly as it does everywhere else.
        self::assertNull($dotenv->value('EMPTY'));
        self::assertNull($dotenv->value('BLANK'));
    }

    public function testALaterAssignmentReplacesAnEarlierOne(): void
    {
        $root = $this->freshDir();
        file_put_contents($root . '/.env.atoms.production', "A=first\nA=second\n");

        self::assertSame('second', AtomsDotenv::forTarget($root, 'production')->value('A'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedProvider(): iterable
    {
        yield 'no assignment' => ["ATOMS_CALLBACK_URL\n", 'line 1 is not a KEY=VALUE assignment'];
        yield 'invalid name' => ["9LIVES=x\n", "line 1 has an invalid variable name '9LIVES'"];
        yield 'dash in name' => ["a-b=x\n", "line 1 has an invalid variable name 'a-b'"];
        yield 'unclosed quote' => ["A=\"open\n", 'line 1 opens a " quote that is never closed'];
        yield 'trailing junk' => ["A='x' y\n", "line 2 has trailing characters after the closing '"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedProvider')]
    public function testAMalformedFileIsE109NamingTheFileAndTheLine(string $contents, string $expected): void
    {
        $root = $this->freshDir();
        // The 'trailing junk' case is written on line 2 so the line number in
        // the message is provably read from the file rather than hardcoded.
        file_put_contents($root . '/.env.atoms.production', str_starts_with($expected, 'line 2') ? "A=ok\n" . $contents : $contents);

        try {
            AtomsDotenv::forTarget($root, 'production');
            self::fail('expected ATOMS-E109');
        } catch (AtomsError $e) {
            self::assertSame(ErrorCode::AtomsEnvFileInvalid, $e->errorCode);
            self::assertStringContainsString('ATOMS-E109', $e->getMessage());
            self::assertStringContainsString($root . '/.env.atoms.production', $e->getMessage());
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    public function testFileNameIsTheOneDocumented(): void
    {
        self::assertSame('.env.atoms.production', AtomsDotenv::fileName('production'));
    }
}
