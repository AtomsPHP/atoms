<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Ai\SkillInstaller;
use Atoms\Cli\Command\AiInstallCommand;
use Atoms\Cli\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class AiInstallCommandTest extends TestCase
{
    public function testWritesFourSkillsAndAgentsMd(): void
    {
        $dir = $this->tempCopy('sample-app');
        $tester = new CommandTester(new AiInstallCommand());

        $exit = $tester->execute(['--root' => $dir]);
        self::assertSame(0, $exit);

        foreach (['atoms-authoring', 'atoms-testing', 'atoms-operating', 'atoms-project-context'] as $skill) {
            self::assertFileExists($dir . '/.claude/skills/' . $skill . '/SKILL.md');
        }
        self::assertFileExists($dir . '/AGENTS.md');
    }

    public function testAuthoringSkillEmbedsTheErrorCatalog(): void
    {
        $dir = $this->tempCopy('sample-app');
        (new CommandTester(new AiInstallCommand()))->execute(['--root' => $dir]);

        $authoring = (string) file_get_contents($dir . '/.claude/skills/atoms-authoring/SKILL.md');
        self::assertStringContainsString('ATOMS-E012', $authoring);
        self::assertStringContainsString('| Code | Title | Fix |', $authoring);
        self::assertStringContainsString(SkillInstaller::MARKER_START, $authoring);
    }

    public function testProjectContextRendersTheManifest(): void
    {
        $dir = $this->tempCopy('sample-app');
        (new CommandTester(new AiInstallCommand()))->execute(['--root' => $dir]);

        $context = (string) file_get_contents($dir . '/.claude/skills/atoms-project-context/SKILL.md');
        self::assertStringContainsString('GameRoom', $context);
        self::assertStringContainsString('getPlayer', $context);
        self::assertStringContainsString('PlayerSnapshot', $context);
    }

    public function testRegenerationPreservesEditsOutsideMarkers(): void
    {
        $dir = $this->tempCopy('sample-app');
        $tester = new CommandTester(new AiInstallCommand());
        $tester->execute(['--root' => $dir]);

        $path = $dir . '/.claude/skills/atoms-authoring/SKILL.md';
        $edited = str_replace('# Authoring Atoms', "# Authoring Atoms\n\nHAND EDITED MARKER LINE.", (string) file_get_contents($path));
        file_put_contents($path, $edited);

        $tester->execute(['--root' => $dir]);

        $after = (string) file_get_contents($path);
        self::assertStringContainsString('HAND EDITED MARKER LINE.', $after, 'edits outside markers must survive regeneration');
        self::assertStringContainsString('ATOMS-E012', $after, 'the generated block must still be present');
        self::assertSame(1, substr_count($after, SkillInstaller::MARKER_START));
    }
}
