<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Build;

use Atoms\Cli\Build\ScoperStage;
use PHPUnit\Framework\TestCase;

final class ScoperStageTest extends TestCase
{
    public function testConfigCarriesPrefixAndExcludesAtoms(): void
    {
        $config = ScoperStage::config('AtomsScoped\\abcd1234');

        self::assertStringContainsString("'prefix' => 'AtomsScoped\\\\abcd1234'", $config);
        self::assertStringContainsString("'exclude-namespaces'", $config);
        self::assertStringContainsString("'Atoms'", $config);
        // The generated config must itself be valid PHP that returns an array.
        $path = sys_get_temp_dir() . '/atoms-scoper-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($path, $config);
        $returned = require $path;
        unlink($path);
        self::assertIsArray($returned);
        self::assertSame('AtomsScoped\\abcd1234', $returned['prefix']);
    }

    public function testComposerCommand(): void
    {
        $command = ScoperStage::composerCommand();

        self::assertSame('composer', $command[0]);
        self::assertContains('install', $command);
        self::assertContains('--no-dev', $command);
    }

    public function testScoperCommand(): void
    {
        $command = ScoperStage::scoperCommand('/bin/php-scoper', '/w/scoper.inc.php', '/w/vendor', '/w/scoped');

        self::assertSame('/bin/php-scoper', $command[0]);
        self::assertSame('add-prefix', $command[1]);
        self::assertContains('--config', $command);
        self::assertContains('/w/scoper.inc.php', $command);
        self::assertContains('--output-dir', $command);
        self::assertContains('/w/scoped', $command);
        self::assertContains('--force', $command);
    }
}
