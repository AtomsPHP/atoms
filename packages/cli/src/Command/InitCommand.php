<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `atoms init` — scaffold atoms.json and an empty atoms-composer.json at the repo
 * root. Idempotent-refuses if atoms.json already exists.
 */
#[AsCommand(name: 'init', description: 'Create atoms.json and atoms-composer.json')]
final class InitCommand extends AbstractCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project slug (defaults to the directory name)');
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'Atoms source path (defaults to app/Atoms)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $this->rootDir($input);
        $atomsJsonPath = $root . '/atoms.json';

        if (is_file($atomsJsonPath)) {
            $output->writeln('<error>atoms.json already exists — refusing to overwrite.</error>');

            return self::FAILURE;
        }

        $projectOpt = $input->getOption('project');
        $project = \is_string($projectOpt) && $projectOpt !== '' ? $projectOpt : basename($root);

        $pathOpt = $input->getOption('path');
        $atomsPath = \is_string($pathOpt) && $pathOpt !== '' ? trim($pathOpt, '/') : 'app/Atoms';

        $atomsJson = [
            'project' => $project,
            'paths' => [
                'atoms' => $atomsPath,
                'shared' => $atomsPath . '/Shared',
            ],
            'php' => '8.3',
            'environments' => [
                'production' => ['endpoint' => 'https://api.atoms.cloud', 'region' => 'iad'],
                'staging' => ['endpoint' => 'https://api.atoms.cloud', 'region' => 'iad'],
            ],
            'callback_url' => [
                'production' => 'https://example.com',
                'staging' => 'https://staging.example.com',
            ],
        ];

        file_put_contents(
            $atomsJsonPath,
            json_encode($atomsJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        $composerPath = $root . '/atoms-composer.json';
        if (!is_file($composerPath)) {
            file_put_contents(
                $composerPath,
                json_encode(['require' => new \stdClass()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            );
        }

        $output->writeln('<info>✓ Wrote atoms.json and atoms-composer.json.</info>');
        $output->writeln('  Next: atoms make:atom GameRoom --with-methods --with-migration');

        return Command::SUCCESS;
    }
}
