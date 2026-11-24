<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Application;
use Atoms\Cli\Tests\Support\FakeWrangler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\StringInput;

final class OperatingSkillCommandsTest extends TestCase
{
    public function testCliExamplesUseRegisteredCommandsAndOptions(): void
    {
        $skill = $this->skill();
        self::assertSame(1, preg_match('/^```sh\R(.*?)^```\r?$/ms', $skill, $block));
        $application = new Application(new FakeWrangler());
        self::assertMatchesRegularExpression('/^atoms dev /m', $block[1]);
        foreach (explode("\n", trim($block[1])) as $line) {
            $example = rtrim(explode('#', $line, 2)[0]);
            self::assertSame(1, preg_match('/^atoms (\S+)(?: (.*))?$/', $example, $parts));
            $this->parseArguments($application, $parts[1], $parts[2] ?? '');
        }
    }

    public function testRotationCommandsStageThePreviousKeyBeforeReplacingTheCurrentKey(): void
    {
        $skill = $this->skill();
        self::assertSame(1, preg_match('/^### Rotate the shared secret\R(.*?)^## /ms', $skill, $section));
        self::assertSame(3, preg_match_all('/`atoms (\S+) ([^`]+)`/', $section[1], $commands, PREG_SET_ORDER));
        $application = new Application(new FakeWrangler());
        $operations = [];
        foreach ($commands as [, $name, $arguments]) {
            $input = $this->parseArguments($application, $name, $arguments);
            $operations[] = [$name, $input->hasParameterOption('--previous'), $input->hasParameterOption('--force')];
        }

        self::assertSame([
            ['shared-secret:set', true, true],
            ['shared-secret:set', false, true],
            ['shared-secret:unset', false, false],
        ], $operations);
    }

    private function skill(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../resources/skills/atoms-operating/SKILL.md');
    }

    private function parseArguments(Application $application, string $name, string $arguments): StringInput
    {
        $input = new StringInput($arguments);
        $input->bind($application->get($name)->getDefinition());
        $input->validate();
        if ($input->hasOption('env')) {
            self::assertNotEmpty($input->getOption('env'), "atoms $name requires --env.");
        }

        return $input;
    }
}
