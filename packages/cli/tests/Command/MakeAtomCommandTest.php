<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Build\SourceParser;
use Atoms\Cli\Command\MakeAtomCommand;
use Atoms\Cli\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class MakeAtomCommandTest extends TestCase
{
    public function testScaffoldsParseValidTwoWorldLayout(): void
    {
        $dir = $this->tempCopy('sample-app');
        $tester = new CommandTester(new MakeAtomCommand());

        $exit = $tester->execute([
            'name' => 'Lobby',
            '--root' => $dir,
            '--with-methods' => true,
            '--with-migration' => true,
            '--websocket' => true,
        ]);

        self::assertSame(0, $exit);

        $atomPath = $dir . '/app/Atoms/Lobby.php';
        $methodsPath = $dir . '/app/Atoms/Lobby/Methods.php';
        $migrationPath = $dir . '/app/Atoms/Lobby/migrations/001_create_lobby_events.sql';

        self::assertFileExists($atomPath);
        self::assertFileExists($methodsPath);
        self::assertFileExists($migrationPath);

        // Namespace derives from the host composer.json PSR-4 map (App\ => app/).
        $atomSource = (string) file_get_contents($atomPath);
        self::assertStringContainsString('namespace App\\Atoms;', $atomSource);
        self::assertStringContainsString('extends Atom', $atomSource);
        self::assertStringContainsString('onConnect', $atomSource);

        // Generated code must be parse-valid.
        $parser = new SourceParser();
        $atom = $parser->parse($atomPath, 'Lobby.php');
        self::assertSame('App\\Atoms\\Lobby', $atom->classes[0]->namespacedName?->toString());
        $parser->parse($methodsPath, 'Methods.php');
    }
}
