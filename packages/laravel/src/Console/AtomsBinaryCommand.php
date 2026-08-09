<?php

declare(strict_types=1);

namespace Atoms\Laravel\Console;

use Illuminate\Console\Command;

/**
 * Shared plumbing for Artisan wrappers that shell out to the `atoms` binary:
 * inject the (replaceable) {@see BinaryRunner} and stream its output through
 * this command's own output.
 */
abstract class AtomsBinaryCommand extends Command
{
    public function __construct(protected readonly BinaryRunner $runner)
    {
        parent::__construct();
    }

    /**
     * @param list<string> $args
     */
    protected function runBinary(array $args): int
    {
        return $this->runner->run($args, function (string $buffer): void {
            $this->output->write($buffer);
        });
    }
}
