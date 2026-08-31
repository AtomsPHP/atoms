<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Ai\SkillInstaller;
use Atoms\Cli\Build\Validator;
use Atoms\Errors\AtomsError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `atoms ai:install` — generate the four agent skills and the AGENTS.md section
 * from this project's manifest and error catalog.
 * Regenerates only between the `atoms:generated` markers, preserving hand edits.
 */
#[AsCommand(name: 'ai:install', description: 'Install/regenerate the Atoms agent skills')]
final class AiInstallCommand extends AbstractCommand
{
    public function __construct(private readonly Validator $validator = new Validator())
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->atomsJson($input);
        } catch (AtomsError $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        // The manifest drives atoms-project-context; validation errors do not
        // block skill generation (the skills exist to help fix those errors).
        $manifest = [];
        try {
            $manifest = $this->validator->validate($config)->manifest;
        } catch (AtomsError $e) {
            $output->writeln('<comment>Proceeding without a manifest: ' . $e->getMessage() . '</comment>');
        }

        $installer = new SkillInstaller($config->rootDir, $config, $manifest);
        $written = $installer->install();

        $output->writeln('<info>✓ Installed Atoms agent skills.</info>');
        foreach ($written as $path) {
            $output->writeln('  ' . $path);
        }

        return self::SUCCESS;
    }
}
