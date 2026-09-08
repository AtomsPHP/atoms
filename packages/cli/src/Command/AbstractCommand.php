<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Cloudflare\CloudflareTarget;
use Atoms\Cli\Config\AtomsJson;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base for every `atoms` command: resolves the repo root (an explicit --root, or
 * the current working directory) and loads the atoms.json anchor from it.
 */
abstract class AbstractCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('root', null, InputOption::VALUE_REQUIRED, 'Repository root (defaults to the current directory)');
    }

    protected function rootDir(InputInterface $input): string
    {
        $root = $input->getOption('root');
        if (\is_string($root) && $root !== '') {
            return rtrim($root, '/');
        }

        $cwd = getcwd();

        return $cwd === false ? '.' : $cwd;
    }

    protected function atomsJson(InputInterface $input): AtomsJson
    {
        return AtomsJson::locate($this->rootDir($input));
    }

    /**
     * Print the resolved configuration and where each value came from, before
     * the command does anything with it.
     *
     * Four sources can supply a setting — a flag, the caller's environment,
     * `.env.atoms.<environment>`, atoms.json — and the nearest one wins
     * silently. Silently is the right behaviour and the wrong thing to leave
     * invisible: this table is what turns "why did it deploy that callback
     * URL" into a line of output rather than a bisection. Values are shown;
     * the API token is represented by its source alone.
     */
    protected static function writeResolvedConfiguration(OutputInterface $output, CloudflareTarget $target): void
    {
        $rows = $target->report();
        $labelWidth = max(array_map(static fn (array $row): int => \strlen($row[0]), $rows));
        $valueWidth = max(array_map(static fn (array $row): int => \strlen($row[1]), $rows));

        $output->writeln('Environment: ' . $target->environment);
        foreach ($rows as [$label, $value, $source]) {
            $output->writeln(sprintf(
                '  %-' . ($labelWidth + 1) . 's %-' . $valueWidth . 's  <comment>(%s)</comment>',
                $label . ':',
                $value,
                $source,
            ));
        }
    }

    /**
     * An option's value when it is a non-empty string, else null — so an
     * unset option and an explicitly empty one resolve the same way, and
     * callers can fall back with `??`.
     */
    protected static function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
