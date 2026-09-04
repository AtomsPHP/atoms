<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Release\RuntimeVersion;
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
            // Deploys go to the user's own Cloudflare account, so there is no
            // Atoms-hosted endpoint to default to. The workers.dev placeholders
            // below are obviously placeholders on purpose: a plausible-looking
            // wrong default is worse than one that cannot be mistaken for real.
            // `debug_endpoints` is the supported switch for the Worker's
            // /debug routes (off by default). It lives here rather than in the
            // committed Worker directory's wrangler.jsonc because that file is
            // shared by every environment, and this is the one setting that
            // must be able to differ between them; `atoms dev` and
            // `atoms deploy` both forward it to Wrangler as a --var.
            //
            // The Worker directory is committed at atoms-worker/ beside this
            // file, so no environment names one.
            'environments' => [
                'production' => [
                    'endpoint' => 'https://' . $project . '.<your-subdomain>.workers.dev',
                    'worker_name' => $project,
                    'account_id' => '',
                    'debug_endpoints' => false,
                ],
                'staging' => [
                    'endpoint' => 'https://' . $project . '-staging.<your-subdomain>.workers.dev',
                    'worker_name' => $project . '-staging',
                    'account_id' => '',
                    'debug_endpoints' => false,
                ],
            ],
            // Where the Worker reaches the app for $this->app()/dispatch().
            // Forwarded by both `atoms dev` and `atoms deploy` as the
            // ATOMS_CALLBACK_URL var, so each entry is live for its
            // environment. A value that differs per machine (a tunnel host)
            // is better left to `ATOMS_CALLBACK_URL` in the environment or
            // `--callback-url`, both of which beat this file.
            'callback_url' => [
                'production' => 'https://example.com/atoms/callback',
                'staging' => 'https://staging.example.com/atoms/callback',
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

        // Build output and the vendor cache live under .atoms/. The Worker
        // directory does not: atoms-worker/ is committed, and its own
        // .gitignore covers everything deploy and dev generate inside it.
        $gitignorePath = $root . '/.gitignore';
        $gitignore = is_file($gitignorePath) ? (string) file_get_contents($gitignorePath) : '';
        if (preg_match('/^\/?\.atoms\/?$/m', $gitignore) !== 1) {
            $prefix = $gitignore === '' || str_ends_with($gitignore, "\n") ? '' : "\n";
            file_put_contents($gitignorePath, $prefix . "/.atoms/\n", FILE_APPEND);
        }

        $output->writeln('<info>✓ Wrote atoms.json and atoms-composer.json.</info>');
        $output->writeln('  Next: atoms make:atom GameRoom --with-methods --with-migration');
        $output->writeln('  Then, to deploy: set each environment\'s "endpoint", "account_id" and "callback_url"');
        $output->writeln('  (or export ATOMS_CALLBACK_URL / pass --callback-url to override the callback URL),');
        $output->writeln('  scaffold the release-matched Worker directory and commit it:');
        $output->writeln('  ' . RuntimeVersion::scaffoldCommand());
        $output->writeln('  cd ' . RuntimeVersion::WORKER_DIR . ' && npm ci && cd - && git add ' . RuntimeVersion::WORKER_DIR);
        $output->writeln('  (' . RuntimeVersion::WORKER_DIR . '/ is part of your repository from now on; its README explains');
        $output->writeln('  which files you own and how `atoms-runtime-cloudflare upgrade` moves it to a new release.)');
        $output->writeln('  Authenticate with Cloudflare — export CLOUDFLARE_API_TOKEN, or use the');
        $output->writeln('  `wrangler login` session you already have — and run `atoms deploy --env staging`.');

        return Command::SUCCESS;
    }
}
