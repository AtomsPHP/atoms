<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Build\ManifestDiff;
use Atoms\Cli\Build\Validator;
use Atoms\Errors\AtomsError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `atoms diff` — compare the current tree's manifest against a saved manifest,
 * labelling each change additive / contracting / breaking. Fetching the live
 * deployed manifest from the platform is a Phase 2 addition; today it diffs
 * against a local file (default `.atoms/build/manifest.json`).
 */
#[AsCommand(name: 'diff', description: 'Diff the current manifest against a saved one')]
final class DiffCommand extends AbstractCommand
{
    public function __construct(private readonly Validator $validator = new Validator())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('against', null, InputOption::VALUE_REQUIRED, 'Saved manifest.json to compare against');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->atomsJson($input);
            $current = $this->validator->validate($config)->manifest;
        } catch (AtomsError $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $againstOpt = $input->getOption('against');
        $againstPath = \is_string($againstOpt) && $againstOpt !== ''
            ? $againstOpt
            : $config->rootDir . '/.atoms/build/manifest.json';

        // NOTE: Phase 2 will fetch the live deployed manifest from the platform.
        $raw = @file_get_contents($againstPath);
        if ($raw === false) {
            $output->writeln("<error>No saved manifest at {$againstPath}. Run `atoms build` first or pass --against.</error>");

            return self::FAILURE;
        }

        try {
            /** @var array<string, mixed> $previous */
            $previous = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $output->writeln('<error>Saved manifest is not valid JSON: ' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $changes = ManifestDiff::compare($previous, $current);
        if ($changes === []) {
            $output->writeln('<info>No manifest changes.</info>');

            return self::SUCCESS;
        }

        foreach ($changes as $change) {
            $style = match ($change['label']) {
                ManifestDiff::BREAKING => 'error',
                ManifestDiff::CONTRACTING => 'comment',
                default => 'info',
            };
            $output->writeln(sprintf('  <%s>%-12s</%s> %s', $style, $change['label'], $style, $change['detail']));
        }

        $output->writeln('');
        $output->writeln('Deploy order: additive → Atoms first; contracting/breaking → monolith first.');

        return self::SUCCESS;
    }
}
