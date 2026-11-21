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
        // Both the host and the archive contain a declaration. Neither loads it in the guest.
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

        // Resolve a different dependency tree which requires the function file at activation.
        $dependency = $root . '/atoms-composer.json';
        file_put_contents($dependency, str_replace('^4.7', '^4.8', (string) file_get_contents($dependency)));
        $build = new CommandTester(new BuildCommand(new Builder(runner: CannedComposer::runner([
            'acme/lib/functions.php' => $polyfill,
        ]))));
        self::assertSame(0, $build->execute(['--root' => $root, '--out' => $out]), $build->getDisplay());
        self::assertSame(0, $validate->execute(['--root' => $root]), $validate->getDisplay());
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
