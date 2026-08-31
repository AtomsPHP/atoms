<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Build\Builder;
use Atoms\Errors\AtomsError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `atoms build` — produce a deterministic, content-addressed bundle + manifest.
 */
#[AsCommand(name: 'build', description: 'Build a deterministic Atoms bundle')]
final class BuildCommand extends AbstractCommand
{
    public function __construct(private readonly Builder $builder = new Builder())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('fast', null, InputOption::VALUE_NONE, 'Skip the vendor stage (the bundle ships no atoms-composer.json packages)');
        $this->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output directory (defaults to .atoms/build)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->atomsJson($input);
            $out = $input->getOption('out');
            $outDir = \is_string($out) && $out !== '' ? $out : $config->rootDir . '/.atoms/build';

            $result = $this->builder->build($config, $outDir, $input->getOption('fast') === true);
        } catch (AtomsError $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $manifest = $result->manifest;
        $atoms = \is_array($manifest['atoms'] ?? null) ? \count($manifest['atoms']) : 0;

        $output->writeln('<info>✓ Build complete.</info>');
        $output->writeln('  bundle:        ' . $result->bundlePath);
        $output->writeln('  manifest:      ' . $result->manifestPath);
        $output->writeln('  content hash:  ' . $result->contentHash);
        $output->writeln('  manifest hash: ' . $result->manifestHash());
        $vendorLine = 'none (fast / no dependencies)';
        if ($result->vendor !== null) {
            $vendorLine = \count($result->vendor->packages) . ' package(s) bundled';
            if ($result->vendor->wroteLock) {
                $vendorLine .= ', atoms-composer.lock written — commit it';
            }
        }
        $output->writeln('  vendor:        ' . $vendorLine);
        $output->writeln('  atom types:    ' . $atoms);

        return self::SUCCESS;
    }
}
