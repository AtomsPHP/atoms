<?php

declare(strict_types=1);

namespace Atoms\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal Phase 1 layering test — not yet a supported product
 */
#[AsCommand(name: 'atoms:deploy', description: 'Deploy the Atoms bundle (shells out to the atoms binary)')]
final class AtomsDeployCommand extends AtomsBinaryCommand
{
    protected function subcommand(): string
    {
        return 'deploy';
    }
}
