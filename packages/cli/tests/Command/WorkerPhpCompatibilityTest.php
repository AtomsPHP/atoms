<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Build\Builder;
use Atoms\Cli\Command\BuildCommand;
use Atoms\Cli\Command\ValidateCommand;
use Atoms\Cli\Tests\Support\CannedComposer;
use Atoms\Cli\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkerPhpCompatibilityTest extends TestCase
{
    private const ATOM = 'app/Atoms/GameRoom.php';
    private const MIGRATION = 'app/Atoms/GameRoom/migrations/003_data.php';

    /** @return iterable<string, array{string, string, int, string}> */
    public static function incompatibleSources(): iterable
    {
        yield 'builtin added after PHP 8.3' => [self::ATOM, <<<'SOURCE'
<?php
namespace App\Atoms;
final class GameRoom extends \Atoms\Atom {
    public function find(): mixed {
        return array_find([-2, 0, 3, 4], static fn (int $n): bool => $n > 0);
    }
}
SOURCE, 5, 'array_find'];
        yield 'property hook' => [self::ATOM, <<<'SOURCE'
<?php
namespace App\Atoms;
final class GameRoom extends \Atoms\Atom {
    public string $label { get => 'room'; }
}
SOURCE, 4, '8.4'];
        yield 'malformed migration' => [self::MIGRATION, "<?php\nreturn new ;", 2, 'unexpected'];
    }

    #[DataProvider('incompatibleSources')]
    public function testCommandsRejectIncompatibleSource(string $file, string $source, int $line, string $reason): void
    {
        $root = $this->tempCopy('sample-app');
        $previous = $this->freshDir();
        $build = new CommandTester(new BuildCommand(new Builder(runner: CannedComposer::runner())));
        self::assertSame(0, $build->execute(['--root' => $root, '--out' => $previous]));
        $before = $this->artifacts($previous);

        file_put_contents($root . '/' . $file, $source);
        $validate = new CommandTester(new ValidateCommand());
        self::assertSame(1, $validate->execute(['--root' => $root, '--json' => true]));
        $result = json_decode($validate->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $matches = array_values(array_filter($result['errors'], static fn (array $error): bool =>
            $error['file'] === $file && $error['line'] === $line));
        self::assertCount(1, $matches, $validate->getDisplay());
        self::assertStringContainsString('8.3.32', $matches[0]['message']);
        self::assertStringContainsStringIgnoringCase($reason, $matches[0]['message']);

        $empty = $this->freshDir();
        foreach ([$empty, $previous] as $out) {
            self::assertSame(1, $build->execute(['--root' => $root, '--out' => $out]));
            self::assertStringContainsString($file . ':' . $line, $build->getDisplay());
            self::assertStringContainsString($matches[0]['message'], $build->getDisplay());
        }
        self::assertSame([], $this->artifacts($empty));
        self::assertSame($before, $this->artifacts($previous));
    }

    /** @return iterable<string, array{string, string}> */
    public static function guestBuiltins(): iterable
    {
        yield 'PHP 8.4' => ['8.4', 'array_find([1, 2], static fn (int $n): bool => $n > 0)'];
        yield 'PHP 8.5' => ['8.5', '[1, 2] |> array_first(...)'];
    }

    #[DataProvider('guestBuiltins')]
    public function testBuildUsesTheSelectedGuestForSyntaxFunctionsAndDependencies(string $series, string $expression): void
    {
        $root = $this->tempCopy('sample-app');
        $config = json_decode((string) file_get_contents($root . '/atoms.json'), true, 512, JSON_THROW_ON_ERROR);
        $config['php'] = $series;
        file_put_contents($root . '/atoms.json', json_encode($config, JSON_THROW_ON_ERROR));
        file_put_contents($root . '/' . self::ATOM, <<<PHP
            <?php
            namespace App\Atoms;
            final class GameRoom extends \Atoms\Atom {
                public function first(): int { return {$expression}; }
            }
            PHP);
        // Application-only files may still use identifiers accepted by a PHP 8.3 host.
        file_put_contents($root . '/app/Atoms/GameRoom/Methods.php', <<<'PHP'
            <?php
            namespace App\Atoms\GameRoom;
            const __PROPERTY__ = 'host';
            final class Methods extends \Atoms\AtomMethods {}
            PHP);
        $validate = new CommandTester(new ValidateCommand());
        self::assertSame(0, $validate->execute(['--root' => $root]), $validate->getDisplay());

        $runner = CannedComposer::runner();
        $install = $runner->resultFor;
        $platform = null;
        $runner->resultFor = static function (array $command, string $cwd) use ($install, &$platform) {
            if ($command[1] === 'install') {
                $staged = json_decode((string) file_get_contents($cwd . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
                $platform = $staged['config']['platform'];
            }
            return $install($command, $cwd);
        };
        $out = $this->freshDir();
        $build = new CommandTester(new BuildCommand(new Builder(runner: $runner)));
        self::assertSame(0, $build->execute(['--root' => $root, '--out' => $out]), $build->getDisplay());
        $manifest = json_decode((string) file_get_contents($out . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($series, $manifest['toolchain']['php']);
        self::assertStringStartsWith($series . '.', $platform['php']);
        self::assertSame($platform['php'], $platform['ext-date']);

        $config['php'] = $series === '8.5' ? '8.4' : '8.3';
        file_put_contents($root . '/atoms.json', json_encode($config, JSON_THROW_ON_ERROR));
        self::assertSame(1, $validate->execute(['--root' => $root]));
        self::assertStringContainsString('PHP ' . $config['php'] . '.', $validate->getDisplay());
    }

    public function testCommandsAcceptGuestFunctionsAndMigrationWithNewerSyntaxInHostMethods(): void
    {
        $root = $this->tempCopy('sample-app');
        file_put_contents($root . '/' . self::ATOM, <<<'SOURCE'
<?php
namespace App\Atoms;
function roomLength(string $value): int { return strlen($value); }
final class GameRoom extends \Atoms\Atom {
    public function size(): int { return roomLength('room'); }
}
SOURCE);
        file_put_contents($root . '/' . self::MIGRATION, <<<'SOURCE'
<?php
return new class implements \Atoms\Migrations\Migration {
    public function up(\Atoms\Database $db): void {}
};
SOURCE);
        file_put_contents($root . '/app/Atoms/GameRoom/Methods.php', <<<'SOURCE'
<?php
namespace App\Atoms\GameRoom;
final class Methods extends \Atoms\AtomMethods {
    public string $label { get => 'host'; }
}
SOURCE);
        $validate = new CommandTester(new ValidateCommand());
        self::assertSame(0, $validate->execute(['--root' => $root]), $validate->getDisplay());
        $build = new CommandTester(new BuildCommand(new Builder(runner: CannedComposer::runner())));
        self::assertSame(0, $build->execute(['--root' => $root, '--out' => $this->freshDir()]), $build->getDisplay());
    }

    public function testOnlyAnEagerAtomDependencyCanSupplyTheMissingBuiltin(): void
    {
        $root = $this->tempCopy('sample-app');
        file_put_contents($root . '/' . self::ATOM, <<<'SOURCE'
<?php
namespace App\Atoms;
final class GameRoom extends \Atoms\Atom {
    public function find(): mixed { return \array_find([3], static fn (int $n): bool => $n > 0); }
}
SOURCE);
        $polyfill = <<<'SOURCE'
<?php
if (PHP_VERSION_ID >= 80400) { return; }
if (!function_exists('array_find')) {
    function array_find(array $items, callable $match): mixed {
        foreach ($items as $key => $item) {
            if ($match($item, $key)) { return $item; }
        }
        return null;
    }
}
SOURCE;
        // The Worker never loads host-polyfill.php or the dependency's optional.php.
        file_put_contents($root . '/host-polyfill.php', $polyfill);
        $validate = new CommandTester(new ValidateCommand());
        self::assertSame(1, $validate->execute(['--root' => $root]));
        self::assertStringContainsString('ATOMS-E004', $validate->getDisplay());
        self::assertFileDoesNotExist($root . '/atoms-composer.lock');
        $build = new CommandTester(new BuildCommand(new Builder(runner: CannedComposer::runner([
            'acme/lib/optional.php' => $polyfill,
        ]))));
        $out = $this->freshDir();
        self::assertSame(1, $build->execute(['--root' => $root, '--out' => $out]));
        self::assertStringContainsString(self::ATOM . ':4', $build->getDisplay());
        self::assertSame([], $this->artifacts($out));
        self::assertSame(1, $validate->execute(['--root' => $root]));

        // The new dependency version puts the polyfill in functions.php, which the Worker loads.
        $dependency = $root . '/atoms-composer.json';
        file_put_contents($dependency, str_replace('^4.7', '^4.8', (string) file_get_contents($dependency)));
        $build = new CommandTester(new BuildCommand(new Builder(runner: CannedComposer::runner([
            'acme/lib/functions.php' => $polyfill,
        ]))));
        self::assertSame(0, $build->execute(['--root' => $root, '--out' => $out]), $build->getDisplay());
        self::assertSame(0, $validate->execute(['--root' => $root]), $validate->getDisplay());
    }

    public function testColdAndWarmBuildsAgreeOnNamespacedVendorHelpersAndPolyfillIncludes(): void
    {
        $root = $this->tempCopy('sample-app');
        file_put_contents($root . '/atoms-composer.json', json_encode([
            'require' => [
                'ramsey/uuid' => '^4.7',
                'nesbot/carbon' => '^3.0',
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/' . self::ATOM, <<<'SOURCE'
<?php
namespace App\Atoms;
final class GameRoom extends \Atoms\Atom {
    public function summary(): string {
        return mb_trim(room_helper() . ' ' . \Symfony\Component\Clock\now());
    }
}
SOURCE);

        $runner = CannedComposer::runner([
            'symfony/clock/Resources/now.php' => <<<'PHP'
                <?php
                namespace Symfony\Component\Clock;
                if (!function_exists(now::class)) {
                    function now(): string { return '2026-01-01T00:00:00Z'; }
                }
                PHP,
            'acme/lib/helpers.php' => <<<'PHP'
                <?php
                namespace App\Atoms;
                function room_helper(): string { return ' sample '; }
                PHP,
            'symfony/polyfill-mbstring/bootstrap.php' => <<<'PHP'
                <?php
                if (\PHP_VERSION_ID >= 80000) {
                    return require __DIR__ . '/bootstrap80.php';
                }
                PHP,
            'symfony/polyfill-mbstring/bootstrap80.php' => <<<'PHP'
                <?php
                if (!function_exists('mb_trim')) {
                    function mb_trim(string $s): string { return trim($s); }
                }
                PHP,
            'composer/autoload_files.php' => <<<'PHP'
                <?php
                $vendorDir = dirname(__DIR__);
                return [
                    'clock' => $vendorDir . '/symfony/clock/Resources/now.php',
                    'helpers' => $vendorDir . '/acme/lib/helpers.php',
                    'mbstring' => $vendorDir . '/symfony/polyfill-mbstring/bootstrap.php',
                ];
                PHP,
        ]);

        $build = new CommandTester(new BuildCommand(new Builder(runner: $runner)));
        $coldOut = $this->freshDir();
        self::assertSame(0, $build->execute(['--root' => $root, '--out' => $coldOut]), $build->getDisplay());
        $composerRunsAfterCold = \count($runner->runs);

        $warmOut = $this->freshDir();
        self::assertSame(0, $build->execute(['--root' => $root, '--out' => $warmOut]), $build->getDisplay());
        self::assertSame($composerRunsAfterCold, \count($runner->runs), 'warm build uses cached vendor tree');
        self::assertSame($this->artifacts($coldOut), $this->artifacts($warmOut));

        $validate = new CommandTester(new ValidateCommand());
        self::assertSame(0, $validate->execute(['--root' => $root]), $validate->getDisplay());
    }

    public function testVersionSwitchingVendorBootstrapPassesAndIndexesPhp83Helper(): void
    {
        $root = $this->tempCopy('sample-app');
        file_put_contents($root . '/' . self::ATOM, <<<'SOURCE'
<?php
namespace App\Atoms;
final class GameRoom extends \Atoms\Atom {
    public function label(): string {
        return \acme_php83_helper();
    }
}
SOURCE);

        $runner = CannedComposer::runner([
            'acme/lib/functions.php' => <<<'PHP'
                <?php
                if (PHP_VERSION_ID >= 80400) {
                    require __DIR__ . '/php84.php';
                } else {
                    require __DIR__ . '/php83.php';
                }
                PHP,
            'acme/lib/php84.php' => <<<'PHP'
                <?php
                final class Hooked {
                    public string $label { get => 'php84'; }
                }
                PHP,
            'acme/lib/php83.php' => <<<'PHP'
                <?php
                if (!function_exists('acme_php83_helper')) {
                    function acme_php83_helper(): string { return 'php83'; }
                }
                PHP,
        ]);

        $build = new CommandTester(new BuildCommand(new Builder(runner: $runner)));
        self::assertSame(0, $build->execute(['--root' => $root, '--out' => $this->freshDir()]), $build->getDisplay());

        $validate = new CommandTester(new ValidateCommand());
        self::assertSame(0, $validate->execute(['--root' => $root]), $validate->getDisplay());
    }

    public function testEarlyReturnGuardedVendorIncludePassesWithoutE003(): void
    {
        $root = $this->tempCopy('sample-app');
        file_put_contents($root . '/' . self::ATOM, <<<'SOURCE'
<?php
namespace App\Atoms;
final class GameRoom extends \Atoms\Atom {
    public function answer(): int {
        return 42;
    }
}
SOURCE);

        $runner = CannedComposer::runner([
            'acme/lib/functions.php' => <<<'PHP'
                <?php
                if (PHP_VERSION_ID < 80400) {
                    return;
                }
                require __DIR__ . '/php84.php';
                PHP,
            'acme/lib/php84.php' => <<<'PHP'
                <?php
                final class Hooked {
                    public string $label { get => 'php84'; }
                }
                PHP,
        ]);

        $build = new CommandTester(new BuildCommand(new Builder(runner: $runner)));
        self::assertSame(0, $build->execute(['--root' => $root, '--out' => $this->freshDir()]), $build->getDisplay());

        $validate = new CommandTester(new ValidateCommand());
        self::assertSame(0, $validate->execute(['--root' => $root]), $validate->getDisplay());
    }

    /** @return iterable<string, array{string}> */
    public static function unconditionalForwardingVendorBootstraps(): iterable
    {
        yield 'require' => ["<?php\nrequire __DIR__ . '/php84.php';\n"];
        yield 'return require_once' => ["<?php\nreturn require_once __DIR__ . '/php84.php';\n"];
    }

    #[DataProvider('unconditionalForwardingVendorBootstraps')]
    public function testUnconditionalForwardingVendorBootstrapFailsWithE003(string $bootstrap): void
    {
        $root = $this->tempCopy('sample-app');
        file_put_contents($root . '/' . self::ATOM, <<<'SOURCE'
<?php
namespace App\Atoms;
final class GameRoom extends \Atoms\Atom {
    public function answer(): int {
        return 42;
    }
}
SOURCE);

        $runner = CannedComposer::runner([
            'acme/lib/functions.php' => $bootstrap,
            'acme/lib/php84.php' => <<<'PHP'
                <?php
                final class Hooked {
                    public string $label { get => 'php84'; }
                }
                PHP,
        ]);

        $out = $this->freshDir();
        $build = new CommandTester(new BuildCommand(new Builder(runner: $runner)));
        self::assertSame(1, $build->execute(['--root' => $root, '--out' => $out]));
        self::assertStringContainsString('ATOMS-E003', $build->getDisplay());
        self::assertStringContainsString('vendor/acme/lib/php84.php', $build->getDisplay());
        self::assertSame([], $this->artifacts($out));

        $validate = new CommandTester(new ValidateCommand());
        self::assertSame(1, $validate->execute(['--root' => $root, '--json' => true]));
        $result = json_decode($validate->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $matches = array_values(array_filter($result['errors'], static fn (array $error): bool =>
            $error['code'] === 'ATOMS-E003' && $error['file'] === 'vendor/acme/lib/php84.php'));
        self::assertCount(1, $matches, $validate->getDisplay());
    }

    /** @return array<string, string|false> */
    private function artifacts(string $directory): array
    {
        $hashes = [];
        foreach (glob($directory . '/*') ?: [] as $file) {
            $hashes[basename($file)] = hash_file('sha256', $file);
        }

        return $hashes;
    }
}
