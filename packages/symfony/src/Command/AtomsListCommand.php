<?php

declare(strict_types=1);

namespace Atoms\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'atoms:list', description: 'List Atoms deployments (shells out to the atoms binary)')]
final class AtomsListCommand extends AtomsBinaryCommand
{
    protected function subcommand(): string
    {
        return 'list';
    }
}
