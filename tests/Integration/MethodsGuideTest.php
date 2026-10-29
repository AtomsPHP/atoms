<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration;

use Atoms\Cli\Tests\TestCase;
use Symfony\Component\Process\Process;

/** Smoke the scaffold command from the guide, using a fresh initialized app. */
final class MethodsGuideTest extends TestCase
{
    public function testDocumentedCommandCreatesTheDocumentedMethodsClass(): void
    {
        $guide = (string) file_get_contents(
            \dirname(__DIR__, 2) . '/docs-site/src/content/docs/guides/methods.md',
        );
        self::assertSame(1, preg_match('/^vendor\/bin\/atoms (make:atom [^\r\n]+)$/m', $guide, $cli));
        self::assertSame(1, preg_match('/^php artisan (make:atom [^\r\n]+)$/m', $guide, $artisan));
        self::assertSame($cli[1], $artisan[1], 'The standalone and Artisan examples must agree.');

        $dir = $this->initializedApp();
        // Execute the arguments read from the docs, not a second hard-coded command.
        $this->runCli($dir, explode(' ', $cli[1]));

        self::assertFileExists($dir . '/app/Atoms/GameRoom.php');
        $methodsPath = 'app/Atoms/GameRoom/Methods.php';
        self::assertStringContainsString('`' . $methodsPath . '`', $guide);
        self::assertFileExists($dir . '/' . $methodsPath);
        $source = (string) file_get_contents($dir . '/' . $methodsPath);
        self::assertStringContainsString('namespace App\\Atoms\\GameRoom;', $source);
        self::assertStringContainsString('`App\\Atoms\\GameRoom` namespace', $guide);
        self::assertStringContainsString('class Methods extends AtomMethods', $source);
    }

    public function testBareCommandOmitsTheOptionalMethodsClass(): void
    {
        $dir = $this->initializedApp();
        $this->runCli($dir, ['make:atom', 'GameRoom']);

        self::assertFileExists($dir . '/app/Atoms/GameRoom.php');
        self::assertFileDoesNotExist($dir . '/app/Atoms/GameRoom/Methods.php');
    }

    private function initializedApp(): string
    {
        $dir = $this->freshDir();
        file_put_contents($dir . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ], JSON_THROW_ON_ERROR));
        $this->runCli($dir, ['init', '--project', 'methods-guide']);

        return $dir;
    }

    /** @param list<string> $args */
    private function runCli(string $dir, array $args): void
    {
        $process = new Process([
            PHP_BINARY,
            \dirname(__DIR__, 2) . '/packages/cli/bin/atoms',
            ...$args,
        ], $dir);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }
}
