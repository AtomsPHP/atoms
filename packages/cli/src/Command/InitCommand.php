<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Config\AtomsDotenv;
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
            // Each target has an explicit Worker name; Wrangler reports the
            // deployed URL, which the app uses as ATOMS_ENDPOINT.
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
                    'worker_name' => $project,
                    'account_id' => '',
                    'debug_endpoints' => false,
                ],
                'staging' => [
                    'worker_name' => $project . '-staging',
                    'account_id' => '',
                    'debug_endpoints' => false,
                ],
            ],
            // Where the Worker reaches the app for $this->app()/dispatch().
            // Forwarded by both `atoms dev` and `atoms deploy` as the
            // ATOMS_CALLBACK_URL var, so each entry is live for its
            // environment. CI may use a whole-value ${VARIABLE} reference;
            // --callback-url or ATOMS_CALLBACK_URL override this file on any
            // command.
            //
            // Empty, not a placeholder host: this file is the committed
            // default for a named deployment, so an example.com left in by
            // accident
            // would POST signed callbacks — carrying method arguments — to a
            // third party, and surface only as ATOMS-E083 ("callback request
            // failed"), which names neither the file nor the key. Empty means
            // "no callback declared": deploy warns, and app()/dispatch() fail
            // with ATOMS-E080, whose fix line says exactly what to set.
            'callback_url' => [
                'production' => '',
                'staging' => '',
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
        //
        // `.env.atoms.<environment>` is ignored for a different reason: it is
        // the per-target environment file the CLI reads below whatever the
        // caller supplied, which makes it the natural home for a local
        // CLOUDFLARE_API_TOKEN or a machine-specific callback URL. Anything in
        // it that is *not* a secret and *is* shared belongs in atoms.json
        // instead — which is the whole reason this file has no committed role
        // to lose by being ignored.
        $gitignorePath = $root . '/.gitignore';
        $gitignore = is_file($gitignorePath) ? (string) file_get_contents($gitignorePath) : '';
        $additions = '';
        if (preg_match('/^\/?\.atoms\/?$/m', $gitignore) !== 1) {
            $additions .= "/.atoms/\n";
        }
        if (preg_match('/^\/?\.env\.atoms\./m', $gitignore) !== 1) {
            $additions .= "/.env.atoms.*\n";
        }
        if ($additions !== '') {
            $prefix = $gitignore === '' || str_ends_with($gitignore, "\n") ? '' : "\n";
            file_put_contents($gitignorePath, $prefix . $additions, FILE_APPEND);
        }

        $output->writeln('<info>✓ Wrote atoms.json and atoms-composer.json.</info>');
        $output->writeln('  Next: atoms make:atom GameRoom --with-methods --with-migration');
        $output->writeln('  Then, to deploy: set each environment\'s "worker_name", "account_id" and "callback_url"');
        $output->writeln('  ("callback_url" starts empty, so $this->app()/dispatch() are unavailable until you set it;');
        $output->writeln('  use "${ATOMS_CALLBACK_URL}" there to explicitly read CI\'s environment),');
        $output->writeln('  scaffold the release-matched Worker directory and commit it:');
        $output->writeln('  ' . RuntimeVersion::scaffoldCommand());
        $output->writeln('  cd ' . RuntimeVersion::WORKER_DIR . ' && npm ci && cd - && git add ' . RuntimeVersion::WORKER_DIR);
        $output->writeln('  (' . RuntimeVersion::WORKER_DIR . '/ is part of your repository from now on; its README explains');
        $output->writeln('  which files you own and how `atoms-runtime-cloudflare upgrade` moves it to a new release.)');
        $output->writeln('  Authenticate with Cloudflare — set CLOUDFLARE_API_TOKEN in '
            . AtomsDotenv::fileName('staging') . ', or use the');
        $output->writeln('  `wrangler login` session you already have — and run `atoms deploy --env staging`.');

        return Command::SUCCESS;
    }
}
