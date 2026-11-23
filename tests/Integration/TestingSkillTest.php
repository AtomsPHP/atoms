<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration;

use Atoms\Laravel\Tests\TestCase;

final class TestingSkillTest extends TestCase
{
    public function testHarnessExampleRunsInProcess(): void
    {
        $imports = <<<'PHP'
            use Atoms\Testing\Tests\Fixtures\ChatRoom;
            use Atoms\Testing\Tests\Fixtures\RecordResult;
            use Atoms\Testing\Tests\Fixtures\Score;
            PHP;
        eval($imports . "\n" . $this->examples()[0]);

        self::assertSame(
            [['body' => 'activated']],
            $harness->db()->query("SELECT body FROM messages WHERE username = 'system'"),
        );
    }

    public function testFakeExampleUsesTheLaravelFacade(): void
    {
        $imports = <<<'PHP'
            use Atoms\Laravel\Facades\Atoms;
            use Atoms\Laravel\Tests\Fixtures\GameRoom;
            PHP;
        eval($imports . "\n" . $this->examples()[1]);
    }

    /** @return array{string, string} */
    private function examples(): array
    {
        $skill = file_get_contents(
            \dirname(__DIR__, 2) . '/packages/cli/resources/skills/atoms-testing/SKILL.md',
        );
        self::assertIsString($skill);
        self::assertSame(2, preg_match_all('/^```php\R(.*?)^```\r?$/ms', $skill, $matches));

        return $matches[1];
    }
}
