<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Cloudflare\CloudflareTarget;
use Atoms\Cli\Cloudflare\Wrangler;
use Atoms\Errors\AtomsError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `atoms shared-secret:unset --env X` — remove `ATOMS_SHARED_SECRET_PREVIOUS`
 * from the Worker, closing a rotation window.
 *
 * The other half of {@see SharedSecretSetCommand}: that one opens the window
 * by setting the overlap value, this one closes it. Without it a rotation
 * could be started from a pipeline but only finished by a human running
 * `wrangler secret delete` from a laptop, and `docs/shared-secret.md`
 * §Rotation reads as though the pipeline covers the whole lifecycle.
 *
 * It targets the overlap key and nothing else. `ATOMS_SHARED_SECRET` itself
 * has no unset path here: removing it makes every route except `GET /healthz`
 * answer `misconfigured`, so it is not an operation to put one typo away from
 * the one that closes a rotation.
 *
 * Idempotent — a Worker that does not carry the key is already in the desired
 * state and reports success, so a pipeline can run this on every deploy after
 * a rotation without failing once the window is closed.
 */
#[AsCommand(
    name: 'shared-secret:unset',
    description: 'Remove ATOMS_SHARED_SECRET_PREVIOUS from the Worker (close a rotation window)',
)]
final class SharedSecretUnsetCommand extends AbstractCommand
{
    public const KEY = SharedSecretSetCommand::PREVIOUS_KEY;

    public function __construct(private readonly Wrangler $wrangler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment');
        $this->addOption('worker-dir', null, InputOption::VALUE_REQUIRED, 'Worker project directory (else atoms.json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = $input->getOption('env');
        if (!\is_string($env) || $env === '') {
            $output->writeln('<error>--env is required</error>');

            return self::FAILURE;
        }

        try {
            $target = CloudflareTarget::resolve(
                $this->atomsJson($input),
                $env,
                null,
                self::stringOption($input, 'worker-dir'),
            );

            if (!$this->isSet($target)) {
                $output->writeln('<info>' . self::KEY . ' is not set for ' . $env . '; nothing to do.</info>');

                return self::SUCCESS;
            }

            $result = $this->wrangler->deleteSecret($target, self::KEY);
            if (!$result->ok()) {
                $output->write($result->stderr);
            }
            $result->assertOk();
        } catch (AtomsError $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln('<info>✓ Removed ' . self::KEY . ' for ' . $env . '.</info>');
        $output->writeln('  Tickets and bearers signed with the old secret are refused from now on.');
        $output->writeln('  Remove it from the app side too; the window is only closed once both are.');

        return self::SUCCESS;
    }

    /**
     * Whether the Worker still carries the overlap key.
     *
     * A `wrangler secret list` that fails or does not parse answers "yes", so
     * the delete is attempted and any real failure surfaces with Wrangler's
     * own message. The opposite reading would report a closed window on an
     * unreadable list, leaving the old secret live and trusted.
     */
    private function isSet(CloudflareTarget $target): bool
    {
        $result = $this->wrangler->listSecrets($target);
        if (!$result->ok()) {
            return true;
        }

        $names = $result->secretNames();
        if ($names === null) {
            return true;
        }

        return \in_array(self::KEY, $names, true);
    }
}
