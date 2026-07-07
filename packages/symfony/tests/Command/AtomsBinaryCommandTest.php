<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Command;

use Atoms\Symfony\Command\AtomsDeployCommand;
use Atoms\Symfony\Command\AtomsListCommand;
use Atoms\Symfony\Command\AtomsRollbackCommand;
use Atoms\Symfony\Tests\Support\FakeProcessRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class AtomsBinaryCommandTest extends TestCase
{
    public function testDeployCommandForwardsArgvToTheAtomsBinary(): void
    {
        $runner = new FakeProcessRunner(['exitCode' => 0, 'stdout' => 'deployed', 'stderr' => '']);
        $command = new AtomsDeployCommand($runner, '/usr/local/bin/atoms');

        $tester = new CommandTester($command);
        $status = $tester->execute(['args' => ['--env', 'staging']]);

        self::assertSame(0, $status);
        self::assertStringContainsString('deployed', $tester->getDisplay());
        self::assertSame([['/usr/local/bin/atoms', 'deploy', '--env', 'staging']], $runner->calls);
    }

    public function testRollbackCommandUsesTheRollbackSubcommand(): void
    {
        $runner = new FakeProcessRunner();
        $command = new AtomsRollbackCommand($runner, '/usr/local/bin/atoms');

        (new CommandTester($command))->execute(['args' => ['--env', 'production']]);

        self::assertSame([['/usr/local/bin/atoms', 'rollback', '--env', 'production']], $runner->calls);
    }

    public function testListCommandUsesTheListSubcommand(): void
    {
        $runner = new FakeProcessRunner();
        $command = new AtomsListCommand($runner, '/usr/local/bin/atoms');

        (new CommandTester($command))->execute(['args' => []]);

        self::assertSame([['/usr/local/bin/atoms', 'list']], $runner->calls);
    }

    public function testNonZeroExitCodeIsSurfacedAsCommandFailure(): void
    {
        $runner = new FakeProcessRunner(['exitCode' => 1, 'stdout' => '', 'stderr' => 'boom']);
        $command = new AtomsDeployCommand($runner, '/usr/local/bin/atoms');

        $tester = new CommandTester($command);
        $status = $tester->execute(['args' => []]);

        self::assertSame(1, $status);
        self::assertStringContainsString('boom', $tester->getDisplay());
    }

    public function testMissingBinaryFailsWithAClearError(): void
    {
        $runner = new FakeProcessRunner();
        $command = new AtomsDeployCommand($runner, null, '/nonexistent/path/so/nothing/matches');

        $originalPath = getenv('PATH');
        putenv('PATH=/nonexistent/path/so/nothing/matches');

        try {
            $tester = new CommandTester($command);
            $status = $tester->execute(['args' => []]);

            self::assertSame(1, $status);
            self::assertStringContainsString('Could not locate', $tester->getDisplay());
            self::assertSame([], $runner->calls);
        } finally {
            putenv($originalPath === false ? 'PATH' : 'PATH=' . $originalPath);
        }
    }
}
