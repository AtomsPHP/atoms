<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Build\SourceParser;
use Atoms\Cli\Command\MakeAtomCommand;
use Atoms\Cli\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

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
        self::assertStringContainsString('@extends Atom<\\App\\Atoms\\Lobby\\Methods>', $atomSource);
        self::assertStringContainsString('onConnect', $atomSource);

        // Generated code must be parse-valid.
        $parser = new SourceParser();
        $atom = $parser->parse($atomPath, 'Lobby.php');
        self::assertSame('App\\Atoms\\Lobby', $atom->classes[0]->namespacedName?->toString());
        $parser->parse($methodsPath, 'Methods.php');
    }

    public function testWithoutMethodsDoesNotReferenceANonexistentClass(): void
    {
        $dir = $this->tempCopy('sample-app');
        $tester = new CommandTester(new MakeAtomCommand());

        self::assertSame(0, $tester->execute(['name' => 'Lobby', '--root' => $dir]));
        self::assertStringNotContainsString('@extends', (string) file_get_contents($dir . '/app/Atoms/Lobby.php'));
        self::assertFileDoesNotExist($dir . '/app/Atoms/Lobby/Methods.php');
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function namespaceLayouts(): iterable
    {
        yield 'default' => ['App\\', 'app/', 'app/Atoms', 'App\\Atoms'];
        yield 'custom prefix' => ['Acme\\Games\\', 'src/', 'src/Atoms', 'Acme\\Games\\Atoms'];
        yield 'nested path' => ['Acme\\', 'src/', 'src/Games/Atoms/Rooms', 'Acme\\Games\\Atoms\\Rooms'];
        yield 'direct mapping' => ['Acme\\Rooms\\', 'src/Rooms/', 'src/Rooms', 'Acme\\Rooms'];
    }

    #[DataProvider('namespaceLayouts')]
    public function testGeneratedCallbackIsTypedAndMisspellingsAreRejected(
        string $prefix,
        string $sourceDir,
        string $atomsPath,
        string $namespace,
    ): void {
        $dir = $this->freshDir();
        file_put_contents($dir . '/atoms.json', json_encode([
            'project' => 'callback-types',
            'paths' => ['atoms' => $atomsPath],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($dir . '/composer.json', json_encode([
            'autoload' => ['psr-4' => [$prefix => $sourceDir]],
        ], JSON_THROW_ON_ERROR));
        $tester = new CommandTester(new MakeAtomCommand());
        self::assertSame(0, $tester->execute([
            'name' => 'GameRoom', '--root' => $dir, '--with-methods' => true,
        ]));

        $atomPath = $dir . '/' . $atomsPath . '/GameRoom.php';
        $methodsPath = $dir . '/' . $atomsPath . '/GameRoom/Methods.php';
        $atomSource = (string) file_get_contents($atomPath);
        self::assertStringContainsString('@extends Atom<\\' . $namespace . '\\GameRoom\\Methods>', $atomSource);
        $atomSource = str_replace(
            '    // Public methods here are invocable over the platform RPC boundary.',
            <<<'PHP'
                public function appLabel(): string
                {
                    return $this->app()->appLabel();
                }
            PHP,
            $atomSource,
        );
        file_put_contents($atomPath, $atomSource);
        file_put_contents($methodsPath, str_replace(
            "class Methods extends AtomMethods\n{\n}",
            <<<'PHP'
            class Methods extends AtomMethods
            {
                public function appLabel(): string
                {
                    return 'Laravel';
                }
            }
            PHP,
            (string) file_get_contents($methodsPath),
        ));

        $root = dirname(__DIR__, 4);
        // Load the generated application's PSR-4 map as Composer would, without
        // a network install or executing application code during analysis.
        file_put_contents($dir . '/autoload.php', '<?php $loader = require '
            . var_export($root . '/vendor/autoload.php', true) . '; $loader->addPsr4('
            . var_export($prefix, true) . ', ' . var_export($dir . '/' . $sourceDir, true) . ');');
        file_put_contents($dir . '/phpstan.neon', "includes:\n    - "
            . $root . "/vendor/atoms/phpstan-rules/rules.neon\n"
            . "parameters:\n    level: 5\n    paths:\n        - " . $atomsPath . "\n"
            . "    bootstrapFiles:\n        - autoload.php\n");

        $analyse = static function () use ($root, $dir): Process {
            $process = new Process([
                PHP_BINARY, $root . '/vendor/phpstan/phpstan/phpstan', 'analyse',
                '--configuration=' . $dir . '/phpstan.neon', '--no-progress',
                '--error-format=json', '--debug',
            ], $dir);
            $process->run();

            return $process;
        };
        $valid = $analyse();
        self::assertSame(0, $valid->getExitCode(), $valid->getOutput() . $valid->getErrorOutput());

        file_put_contents($atomPath, str_replace('->appLabel()', '->appLabl()', $atomSource));
        $invalid = $analyse();
        self::assertSame(1, $invalid->getExitCode(), $invalid->getOutput() . $invalid->getErrorOutput());
        // --debug prints analysed filenames before the JSON report.
        $output = $invalid->getOutput();
        $report = json_decode(substr($output, strpos($output, '{')), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['errors' => 0, 'file_errors' => 1], $report['totals']);
        self::assertSame('method.notFound', $report['files'][$atomPath]['messages'][0]['identifier']);
        self::assertSame(
            'Call to an undefined method ' . $namespace . '\\GameRoom\\Methods::appLabl().',
            $report['files'][$atomPath]['messages'][0]['message'],
        );
    }
}
