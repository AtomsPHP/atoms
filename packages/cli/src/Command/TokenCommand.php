<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Cli\Cloudflare\CloudflareTarget;
use Atoms\Cli\Cloudflare\DevVars;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `atoms token` — print the bearer derived from `ATOMS_SHARED_SECRET`.
 *
 * The shared secret configures the app <-> Worker boundary (docs/shared-secret.md)
 * and must never be sent as an `Authorization` header. The wire value is
 * `base64_encode(hash_hkdf('sha256', $secret, 32, 'atoms/bearer/v1', ''))`,
 * which this command derives and prints, so an operator can reach a Worker
 * without ever pasting the root secret into a header:
 *
 *     curl -H "Authorization: Bearer $(atoms token)" https://your-worker.example
 *
 * On success, only the derived bearer plus a trailing newline reaches
 * stdout — nothing else — so the command substitution above works. The secret
 * itself is never printed.
 *
 * The secret is read, in order: from the `ATOMS_SHARED_SECRET` process
 * environment variable; else from the `ATOMS_SHARED_SECRET` line of the
 * Worker project's `.dev.vars` (`--worker-dir`, or the directory `atoms.json`
 * resolves for `--env`).
 */
#[AsCommand(name: 'token', description: 'Print the bearer derived from ATOMS_SHARED_SECRET')]
final class TokenCommand extends AbstractCommand
{
    /** HKDF `info` string for the bearer purpose (docs/shared-secret.md). */
    private const INFO = 'atoms/bearer/v1';

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('env', null, InputOption::VALUE_REQUIRED, 'Environment to resolve the Worker directory for (fallback secret source)', 'staging');
        $this->addOption('worker-dir', null, InputOption::VALUE_REQUIRED, 'Worker project directory holding .dev.vars (else atoms.json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $secret = $this->decodeSecret($this->resolveSecret($input));
            $bearer = base64_encode(hash_hkdf('sha256', $secret, 32, self::INFO, ''));
        } catch (AtomsError $e) {
            $this->errorOutput($output)->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        $output->writeln($bearer);

        return self::SUCCESS;
    }

    /**
     * @throws AtomsError E105 when no secret is configured anywhere
     */
    private function resolveSecret(InputInterface $input): string
    {
        $env = getenv('ATOMS_SHARED_SECRET');
        if (\is_string($env) && $env !== '') {
            return $env;
        }

        $workerDir = $this->resolveWorkerDir($input);
        if ($workerDir !== null) {
            $fromFile = DevVars::readSecret($workerDir);
            if ($fromFile !== null) {
                return $fromFile;
            }
        }

        throw $this->missingSecret();
    }

    /**
     * An explicit --worker-dir, or the directory atoms.json resolves for
     * --env. Any failure resolving the latter (no atoms.json, no such
     * environment) yields null rather than propagating — the caller's fallback
     * is a plain "no secret configured" error, not an unrelated atoms.json one.
     */
    private function resolveWorkerDir(InputInterface $input): ?string
    {
        $explicit = self::stringOption($input, 'worker-dir');
        if ($explicit !== null) {
            return rtrim($explicit, '/');
        }

        try {
            $target = CloudflareTarget::resolve(
                $this->atomsJson($input),
                self::stringOption($input, 'env') ?? 'staging',
                null,
                null,
                requireCredentials: false,
            );

            return $target->workerDir;
        } catch (AtomsError) {
            return null;
        }
    }

    /**
     * @throws AtomsError E105 when $raw does not decode to exactly 32 bytes
     */
    private function decodeSecret(string $raw): string
    {
        $trimmed = trim($raw);
        $decoded = $trimmed === '' ? false : base64_decode($trimmed, true);

        if (!\is_string($decoded) || \strlen($decoded) !== 32) {
            throw $this->missingSecret();
        }

        return $decoded;
    }

    private function missingSecret(): AtomsError
    {
        return new AtomsError(ErrorCode::SharedSecretMissing, ErrorCatalog::format(ErrorCode::SharedSecretMissing));
    }

    private function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }
}
