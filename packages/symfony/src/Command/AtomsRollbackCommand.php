<?php

declare(strict_types=1);

namespace Atoms\Symfony\Command;

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal Phase 1 layering test — not yet a supported product
 */
#[AsCommand(name: 'atoms:rollback', description: 'Roll back the deployed Atoms bundle (shells out to the atoms binary)')]
final class AtomsRollbackCommand extends AtomsBinaryCommand
{
    protected function subcommand(): string
    {
        return 'rollback';
    }
}
