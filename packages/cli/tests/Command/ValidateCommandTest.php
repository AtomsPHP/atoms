<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Command\ValidateCommand;
use Atoms\Cli\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ValidateCommandTest extends TestCase
{
    public function testCleanProjectSucceeds(): void
    {
        $tester = new CommandTester(new ValidateCommand());
        $exit = $tester->execute(['--root' => $this->fixtureDir('sample-app')]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No boundary violations', $tester->getDisplay());
    }

    public function testViolatingProjectFailsWithCodes(): void
    {
        $tester = new CommandTester(new ValidateCommand());
        $exit = $tester->execute(['--root' => $this->fixtureDir('violating-app')]);

        $display = $tester->getDisplay();
        self::assertSame(1, $exit);
        self::assertStringContainsString('ATOMS-E012', $display);
        self::assertStringContainsString('ATOMS-E051', $display);
        self::assertStringContainsString('Fix:', $display);
    }

    public function testJsonOutput(): void
    {
        $tester = new CommandTester(new ValidateCommand());
        $exit = $tester->execute(['--root' => $this->fixtureDir('violating-app'), '--json' => true]);

        self::assertSame(1, $exit);
        $decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($decoded['ok']);
        self::assertNotEmpty($decoded['manifest_hash']);
        $codes = array_column($decoded['errors'], 'code');
        self::assertContains('ATOMS-E030', $codes);
    }
}
