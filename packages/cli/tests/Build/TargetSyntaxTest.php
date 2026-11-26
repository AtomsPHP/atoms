<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Build;

use Atoms\Cli\Build\TargetSyntax;
use Atoms\Cli\Build\Validator;
use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Tests\TestCase;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;
use PHPUnit\Framework\Attributes\DataProvider;

final class TargetSyntaxTest extends TestCase
{
    #[DataProvider('newerSyntax')]
    public function testSyntaxIsCheckedAgainstTheWorker(string $snippet, string $minimum): void
    {
        $violation = (new TargetSyntax())->check("<?php\n\n" . $snippet, 'app/Atoms/Room.php');

        self::assertNotNull($violation);
        self::assertSame(ErrorCode::IncompatibleWorkerSyntax, $violation->code);
        self::assertSame('app/Atoms/Room.php', $violation->file);
        self::assertSame(3, $violation->line);
        self::assertStringContainsString('PHP 8.3.32', $violation->message());
        self::assertStringContainsString('Fix:', $violation->message());

        foreach (['8.4', '8.5'] as $target) {
            $error = (new TargetSyntax($target))->check('<?php ' . $snippet, 'Room.php');
            self::assertSame(version_compare($target, $minimum, '>='), $error === null, $target . ' ' . $error?->message());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function newerSyntax(): iterable
    {
        yield 'property hooks' => ['class Room { public string $name { get => "room"; } }', '8.4'];
        yield 'asymmetric visibility' => ['class Room { public private(set) string $name; }', '8.4'];
        yield 'final property' => ['class Room { final public string $name; }', '8.4'];
        yield 'final promoted property' => ['class Room { function __construct(final public string $name) {} }', '8.5'];
        yield 'asymmetric static property' => ['class Room { public private(set) static string $name; }', '8.5'];
        yield 'void cast' => ['(void) strlen("room");', '8.5'];
        yield 'direct dereference of new' => ['new Room()->name();', '8.4'];
        yield 'pipe operator' => ['return "room" |> trim(...);', '8.5'];
        yield 'first-class callable in constant' => ['class Room { public const FORMAT = \strlen(...); }', '8.5'];
        yield 'closure in constant' => ['class Room { public const FN = static function () {}; }', '8.5'];
        yield 'arrow function in constant' => ['class Room { public const FN = static fn () => 1; }', '8.5'];
        yield 'cast in parameter default' => ['function room(int $id = (int) "1"): int { return $id; }', '8.5'];
        yield 'cast in asymmetric promoted default' => ['class Room { function __construct(public private(set) int $id = (int) "1") {} }', '8.5'];
        yield 'cast in constant' => ['const ROOM_ID = (int) "1";', '8.5'];
        yield 'cast in enum case' => ['enum RoomKind: int { case Main = (int) "1"; }', '8.5'];
        yield 'trailing comma in exit' => ['exit(0,);', '8.4'];
        yield 'trailing comma in die' => ['die(0, /* comment */);', '8.4'];
        yield 'exit first-class callable' => ['$fn = exit(...);', '8.4'];
        yield 'clone with' => ['$copy = clone($room, ["name" => "lobby"]);', '8.5'];
        yield 'attribute on non-class const' => ['#[\Deprecated] const ROOM = "lobby";', '8.5'];
    }

    public function testAcceptsPhp83Syntax(): void
    {
        $source = <<<'PHP'
<?php
class Room
{
    #[\Deprecated]
    const string NAME = 'room';

    public function __construct(public readonly string $name = self::NAME) {}
}
enum RoomKind: int
{
    case Main = 1;
}
function nextId(): int
{
    static $id = (int) '1';
    return $id;
}
$name = (new Room())->name;
$format = trim(...);
$copy = clone(new Room());
exit(0);
PHP;

        self::assertNull((new TargetSyntax())->check($source, 'app/Atoms/Room.php'));
    }

    public function testValidatorChecksPhpMigrationsAndKeepsHostSyntaxOutOfTheCheck(): void
    {
        $root = $this->tempCopy('sample-app');
        $host = $root . '/app/Atoms/GameRoom/Methods.php';
        $contents = (string) file_get_contents($host);
        file_put_contents($host, str_replace('        return ', "        new \\stdClass()->unused();\n        return ", $contents));

        $migration = 'app/Atoms/GameRoom/migrations/003_pipe.php';
        file_put_contents($root . '/' . $migration, "<?php\nreturn 'room' |> trim(...);\n");

        $result = (new Validator())->validate(AtomsJson::load($root . '/atoms.json'));

        self::assertCount(1, $result->errors);
        self::assertSame(ErrorCode::IncompatibleWorkerSyntax, $result->errors[0]->code);
        self::assertSame($migration, $result->errors[0]->file);
        self::assertSame(2, $result->errors[0]->line);
    }

    public function testDiscoveryReportsAParseErrorWithTheRelativeFileAndLine(): void
    {
        $root = $this->tempCopy('sample-app');
        $file = 'app/Atoms/GameRoom/Support/Broken.php';
        file_put_contents($root . '/' . $file, "<?php\nnamespace App\\Atoms\\GameRoom\\Support;\nclass Broken { public ); }\n");

        $result = (new Validator())->validate(AtomsJson::load($root . '/atoms.json'));

        self::assertCount(1, $result->errors);
        self::assertSame(ErrorCode::IncompatibleWorkerSyntax, $result->errors[0]->code);
        self::assertSame($file, $result->errors[0]->file);
        self::assertSame(3, $result->errors[0]->line);
    }

    public function testDiscoveryAcceptsPhp83IdentifiersReservedByNewerPhp(): void
    {
        $root = $this->tempCopy('sample-app');
        $file = 'app/Atoms/GameRoom/Support/Legacy.php';
        file_put_contents($root . '/' . $file, <<<'PHP'
<?php
namespace App\Atoms\GameRoom\Support;
const __PROPERTY__ = 1;
final class Legacy {}
PHP);

        $result = (new Validator())->validate(AtomsJson::load($root . '/atoms.json'));

        self::assertSame([], $result->errors);
        self::assertContains($file, array_column($result->bundleFiles->files, 'relative'));
    }

    public function testUnsupportedTargetCannotSetTheManifestPhpVersion(): void
    {
        $root = $this->tempCopy('sample-app');
        $config = $root . '/atoms.json';
        $contents = (string) file_get_contents($config);
        file_put_contents($config, str_replace('"project":', '"php": "8.6", "project":', $contents));

        $this->expectException(AtomsError::class);
        $this->expectExceptionMessage('"php" must be one of 8.3, 8.4, 8.5');
        AtomsJson::load($config);
    }
}
