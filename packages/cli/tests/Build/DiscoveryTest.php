<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Build;

use Atoms\Cli\Build\ClassKind;
use Atoms\Cli\Build\Discovery;
use Atoms\Cli\Build\Violation;
use Atoms\Cli\Tests\TestCase;

final class DiscoveryTest extends TestCase
{
    public function testClassifiesEachFileKind(): void
    {
        $result = (new Discovery())->discover($this->sampleApp());

        $kinds = [];
        foreach ($result->classes as $class) {
            $kinds[$class->fqcn] = $class->kind();
        }

        self::assertSame(ClassKind::Atom, $kinds['App\\Atoms\\GameRoom']);
        self::assertSame(ClassKind::Methods, $kinds['App\\Atoms\\GameRoom\\Methods']);
        self::assertSame(ClassKind::Job, $kinds['App\\Atoms\\Jobs\\RecordGameResult']);
        self::assertSame(ClassKind::Shared, $kinds['App\\Atoms\\Shared\\PlayerSnapshot']);
        self::assertSame(ClassKind::Support, $kinds['App\\Atoms\\GameRoom\\Support\\ScoreBoard']);
        self::assertSame([], $result->violations);
    }

    public function testUnclassifiableFileWarns(): void
    {
        $result = (new Discovery())->discover($this->violatingApp());

        self::assertCount(1, $result->violations);
        self::assertSame('ATOMS-E001', $result->violations[0]->code->value);
        self::assertStringEndsWith('Helper.php', $result->violations[0]->file);
    }

    /**
     * The FQCN index keeps the last declaration of a name, so an earlier one is
     * dropped from it. That must not turn into a verdict about the file it came
     * from: both files here declare a real Atom, and the collision itself is
     * what the build needs to hear about.
     */
    public function testTwoFilesDeclaringOneClassAreReportedAsACollision(): void
    {
        $result = (new Discovery())->discover($this->duplicateFqcnApp());

        $codes = array_map(
            static fn (Violation $v): string => $v->code->value,
            $result->violations,
        );

        self::assertSame(['ATOMS-E002'], $codes, 'neither file is unclassifiable');

        $collision = $result->violations[0];
        self::assertTrue($collision->isError(), 'a bundle carrying both files fatals in the guest');
        self::assertSame('App\\Atoms\\GameRoom', $collision->symbol);
        self::assertStringContainsString('App\\Atoms\\GameRoom', $collision->message());
        self::assertStringContainsString('app/Atoms/GameRoom.php', $collision->message());
        self::assertStringContainsString('app/Atoms/Legacy/GameRoom.php', $collision->message());
    }

    /**
     * The dropped copy is still one of the objects discovery parsed, so it
     * carries the classification of what it actually is — the specific defect
     * behind the false ATOMS-E001 above.
     */
    public function testTheDroppedCopyIsClassifiedToo(): void
    {
        $result = (new Discovery())->discover($this->duplicateFqcnApp());

        self::assertCount(1, $result->classes, 'one FQCN, one index entry');
        self::assertSame(ClassKind::Atom, $result->classes[0]->kind());
        self::assertStringEndsWith(
            'app/Atoms/Legacy/GameRoom.php',
            $result->classes[0]->relativePath,
            'the last declaration is the one the index keeps',
        );
    }
}
