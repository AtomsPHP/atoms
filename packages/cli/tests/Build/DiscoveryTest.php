<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Build;

use Atoms\Cli\Build\ClassKind;
use Atoms\Cli\Build\Discovery;
use Atoms\Cli\Tests\TestCase;

final class DiscoveryTest extends TestCase
{
    public function testClassifiesEachFileKind(): void
    {
        $result = (new Discovery())->discover($this->sampleApp());

        $kinds = [];
        foreach ($result->classes as $class) {
            $kinds[$class->fqcn] = $class->kind;
        }

        self::assertSame(ClassKind::Atom, $kinds['App\\Atoms\\GameRoom']);
        self::assertSame(ClassKind::Methods, $kinds['App\\Atoms\\GameRoom\\Methods']);
        self::assertSame(ClassKind::Job, $kinds['App\\Atoms\\Jobs\\RecordGameResult']);
        self::assertSame(ClassKind::Shared, $kinds['App\\Atoms\\Shared\\PlayerSnapshot']);
        self::assertSame([], $result->warnings);
    }

    public function testUnclassifiableFileWarns(): void
    {
        $result = (new Discovery())->discover($this->violatingApp());

        self::assertCount(1, $result->warnings);
        self::assertSame('ATOMS-E001', $result->warnings[0]->code->value);
        self::assertStringEndsWith('Helper.php', $result->warnings[0]->file);
    }
}
