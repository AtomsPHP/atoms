<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration;

use Atoms\Laravel\Tests\TestCase;

final class TestingSkillTest extends TestCase
{
    public function testPhpExamplesRunAgainstTheHarnessAndLaravelFake(): void
    {
        $skill = file_get_contents(
            \dirname(__DIR__, 2) . '/packages/cli/resources/skills/atoms-testing/SKILL.md',
        );
        self::assertIsString($skill);
        self::assertSame(2, preg_match_all('/^```php\R(.*?)^```\r?$/ms', $skill, $examples));

        eval('use Atoms\Testing\Tests\Fixtures\ChatRoom;' . "\n" . $examples[1][0]);
        $imports = <<<'PHP'
            use Atoms\Laravel\Facades\Atoms;
            use Atoms\Laravel\Tests\Fixtures\GameRoom;
            PHP;
        eval($imports . "\n" . $examples[1][1]);
    }
}
