<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Cloudflare\CloudflareTarget;
use Atoms\Cli\Cloudflare\Wrangler;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `atoms secrets:root --env X` — store `ATOMS_SHARED_SECRET` on the Worker,
 * reading it from STDIN.
 *
 * The companion to {@see SecretsSetCommand}, which deliberately refuses this
 * key (`ATOMS-E077`): that command prefixes every name with
 * `ATOMS_CONFIG_`, the namespace Atom code can read, and the boundary root
 * must never live there. This one writes the exact, unprefixed name and is
 * the only CLI path to it.
 *
 * Exists for CI. Without it a pipeline can deploy a Worker but not configure
 * it, so the first deploy ships something that answers `misconfigured` on
 * every route but `GET /healthz` — green health check, broken Worker — until
 * a human runs `wrangler secret put` from a laptop.
 *
 * The value is read from STDIN only, never an argument: an argv is visible in
 * a process listing and a CI log. It is validated as 32 bytes of base64
 * before being sent, so a malformed pipeline secret fails here rather than
 * becoming a Worker that 500s.
 *
 * Idempotent by default — an existing secret of this name is left alone, so
 * running it on every deploy does not mint a Worker version each time.
 * `--force` overwrites, which is how a rotation is applied
 * (docs/shared-secret.md §Rotation).
 */
#[AsCommand(name: 'secrets:root', description: 'Set ATOMS_SHARED_SECRET on the Worker, read from STDIN')]
final class SecretsRootCommand extends AbstractCommand
{
    public const KEY = 'ATOMS_SHARED_SECRET';

    public const PREVIOUS_KEY = 'ATOMS_SHARED_SECRET_PREVIOUS';

    /** @var resource|null */
    private $stdin;

    /**
     * @param resource|null $stdin the value source; defaults to STDIN
     */
    public function __construct(private readonly Wrangler $wrangler, $stdin = null)
    {
        $this->stdin = $stdin;
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment');
        $this->addOption('worker-dir', null, InputOption::VALUE_REQUIRED, 'Worker project directory (else atoms.json)');
        $this->addOption('previous', null, InputOption::VALUE_NONE, 'Set ' . self::PREVIOUS_KEY . ' instead (rotation overlap)');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite an existing value (how a rotation is applied)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = $input->getOption('env');
        if (!\is_string($env) || $env === '') {
            $output->writeln('<error>--env is required</error>');

            return self::FAILURE;
        }

        $key = $input->getOption('previous') === true ? self::PREVIOUS_KEY : self::KEY;

        try {
            $value = $this->readStdin();
            $this->assertWellFormed($value, $key);

            $target = CloudflareTarget::resolve(
                $this->atomsJson($input),
                $env,
                null,
                self::stringOption($input, 'worker-dir'),
            );

            if ($input->getOption('force') !== true && $this->alreadySet($target, $key)) {
                $output->writeln('<info>' . $key . ' is already set for ' . $env . '; leaving it alone.</info>');
                $output->writeln('  Pass --force to overwrite it (a rotation).');

                return self::SUCCESS;
            }

            $result = $this->wrangler->putSecret($target, $key, $value);
            if (!$result->ok()) {
                $output->write($result->stderr);
            }
            $result->assertOk();
        } catch (AtomsError $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln('<info>✓ Set ' . $key . ' for ' . $env . '.</info>');
        $output->writeln('  The app side needs the identical value; it is never sent over the wire.');

        return self::SUCCESS;
    }

    /**
     * Whether the Worker already carries $key.
     *
     * A `wrangler secret list` that fails or does not parse answers "no": the
     * cost of a redundant put is a Worker version, the cost of a wrong skip is
     * a Worker that never gets its secret.
     */
    private function alreadySet(CloudflareTarget $target, string $key): bool
    {
        $result = $this->wrangler->listSecrets($target);
        if (!$result->ok()) {
            return false;
        }

        $decoded = json_decode($result->stdout, true);
        if (!\is_array($decoded)) {
            return false;
        }

        foreach ($decoded as $entry) {
            $name = \is_array($entry) ? ($entry['name'] ?? null) : $entry;
            if ($name === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws AtomsError E105 when $value is not 32 bytes of base64
     */
    private function assertWellFormed(string $value, string $key): void
    {
        $decoded = $value === '' ? false : base64_decode($value, true);

        if (!\is_string($decoded) || \strlen($decoded) !== 32) {
            throw new AtomsError(
                ErrorCode::SharedSecretMissing,
                ErrorCatalog::format(ErrorCode::SharedSecretMissing) . ' Read ' . \strlen($value)
                    . ' bytes on STDIN for ' . $key . '.',
            );
        }
    }

    private function readStdin(): string
    {
        $data = @stream_get_contents($this->stdin ?? \STDIN);

        return \is_string($data) ? trim($data) : '';
    }
}
