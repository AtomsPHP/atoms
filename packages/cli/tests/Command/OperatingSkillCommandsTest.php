<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Command;

use Atoms\Cli\Application;
use Atoms\Cli\Tests\Support\FakeWrangler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\StringInput;

final class OperatingSkillCommandsTest extends TestCase
{
    public function testCommandExamplesWorkWithAndWithoutTheirOptionalArguments(): void
    {
        $skill = $this->skill();
        self::assertSame(1, preg_match('/^```sh\R(.*?)^```\r?$/ms', $skill, $block));
        $application = new Application(new FakeWrangler());
        $names = [];

        foreach (explode("\n", trim($block[1])) as $line) {
            $example = trim(explode('#', $line, 2)[0]);
            self::assertMatchesRegularExpression('/^atoms \S+/', $example);
            [, $name, $usage] = array_pad(explode(' ', $example, 3), 3, '');
            $names[] = $name;

            $required = preg_replace('/\[[^\]]+\]/', '', $usage);
            $all = str_replace(['[', ']'], '', $usage);
            foreach (array_unique([$required, $all]) as $arguments) {
                $input = $this->parseArguments($application, $name, $arguments);
                if ($input->hasOption('env')) {
                    self::assertNotEmpty($input->getOption('env'), "$example requires an explicit --env.");
                }
            }
        }

        self::assertContains('dev', $names);
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
            self::assertNotEmpty($input->getOption('env'));
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

        return $input;
    }
}
