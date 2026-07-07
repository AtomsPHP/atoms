<?php

declare(strict_types=1);

namespace Atoms\Symfony\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal Phase 1 layering test — not yet a supported product
 *
 * Base for the atoms:* console wrappers. Every subcommand shells out to the
 * real `atoms` binary rather than reimplementing CLI logic in the bundle —
 * doing the latter would pull atoms/cli into atoms/symfony, which is exactly
 * the layering violation this package exists to catch (integration-plan
 * §5.3). Binary discovery order: `vendor/bin/atoms`, then $PATH, then
 * `packages/cli/bin/atoms` (monorepo dev checkouts).
 */
abstract class AtomsBinaryCommand extends Command
{
    public function __construct(
        private readonly ProcessRunner $runner = new ProcOpenProcessRunner(),
        private readonly ?string $binaryPath = null,
        private readonly ?string $projectDir = null,
    ) {
        parent::__construct();
    }

    /**
     * The `atoms` subcommand this wrapper forwards to (e.g. 'deploy').
     */
    abstract protected function subcommand(): string;

    protected function configure(): void
    {
        $this->addArgument('args', InputArgument::IS_ARRAY, 'Arguments forwarded to `atoms ' . $this->subcommand() . '`');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $binary = $this->binaryPath ?? $this->discoverBinary();

        if ($binary === null) {
            $output->writeln('<error>Could not locate the `atoms` binary (checked vendor/bin/atoms, $PATH, packages/cli/bin/atoms).</error>');

            return Command::FAILURE;
        }

        /** @var list<string> $extraArgs */
        $extraArgs = $input->getArgument('args');
        $result = $this->runner->run([$binary, $this->subcommand(), ...$extraArgs]);

        if ($result['stdout'] !== '') {
            $output->write($result['stdout']);
        }
        if ($result['stderr'] !== '') {
            $output->write($result['stderr']);
        }

        return $result['exitCode'] === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function discoverBinary(): ?string
    {
        $root = $this->projectDir ?? getcwd();

        if (is_string($root) && $root !== '') {
            $vendorBinary = rtrim($root, '/') . '/vendor/bin/atoms';
            if (is_file($vendorBinary) && is_executable($vendorBinary)) {
                return $vendorBinary;
            }
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir === '') {
                continue;
            }

            $candidate = rtrim($dir, '/') . '/atoms';
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        if (is_string($root) && $root !== '') {
            $monorepoBinary = rtrim($root, '/') . '/packages/cli/bin/atoms';
            if (is_file($monorepoBinary)) {
                return $monorepoBinary;
            }
        }

        return null;
    }
}
