<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Config\AtomsJson;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

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
