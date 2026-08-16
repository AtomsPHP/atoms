<?php

declare(strict_types=1);

namespace Atoms\Cli\Cloudflare;

use Atoms\Cli\Process\ProcessRunner;
use Atoms\Cli\Process\SymfonyProcessRunner;

/**
 * Drives a real, locally installed Wrangler as a subprocess.
 *
 * Not exercised by the test suite, which fakes {@see Wrangler} — the rule that
 * tests never hit the network is what makes that mandatory rather than
 * convenient. `wrangler deploy` talks to Cloudflare.
 */
final class ProcessWrangler implements Wrangler
{
    /** Wrangler's own default; every API call is bounded by it. */
    private const TIMEOUT_SECONDS = 600.0;

    private readonly ProcessRunner $runner;

    public function __construct(?ProcessRunner $runner = null)
    {
        $this->runner = $runner ?? new SymfonyProcessRunner();
    }

    public function deploy(CloudflareTarget $target, array $vars = []): WranglerResult
    {
        $argv = ['deploy', '--name', $target->workerName];
        foreach ($vars as $name => $value) {
            $argv[] = '--var';
            $argv[] = $name . ':' . $value;
        }

        return $this->run($target, $argv);
    }

    public function versions(CloudflareTarget $target): WranglerResult
    {
        return $this->run($target, ['versions', 'list', '--name', $target->workerName, '--json']);
    }

    public function rollback(CloudflareTarget $target, ?string $versionId, ?string $message): WranglerResult
    {
        $argv = ['rollback'];
        if ($versionId !== null && $versionId !== '') {
            $argv[] = $versionId;
        }
        $argv[] = '--name';
        $argv[] = $target->workerName;
        if ($message !== null && $message !== '') {
            $argv[] = '--message';
            $argv[] = $message;
        }
        // Non-interactive by construction: the CLI is scripted and CI has no
        // one to answer a prompt.
        $argv[] = '--yes';

        return $this->run($target, $argv);
    }

    public function putSecret(CloudflareTarget $target, string $key, string $value): WranglerResult
    {
        // The value goes on stdin, never in argv — argv is visible to every
        // other process on the machine.
        return $this->run($target, ['secret', 'put', $key, '--name', $target->workerName], $value);
    }

    public function listSecrets(CloudflareTarget $target): WranglerResult
    {
        return $this->run($target, ['secret', 'list', '--name', $target->workerName, '--format', 'json']);
    }

    public function deleteSecret(CloudflareTarget $target, string $key): WranglerResult
    {
        return $this->run($target, ['secret', 'delete', $key, '--name', $target->workerName]);
    }

    public function dev(CloudflareTarget $target, string $port, array $vars): WranglerResult
    {
        $argv = ['dev', '--port', $port];
        foreach ($vars as $name => $value) {
            $argv[] = '--var';
            $argv[] = $name . ':' . $value;
        }

        $binary = WranglerBinary::resolve($this->runner, $target);
        $command = [$binary, ...$argv];

        $result = $this->runner->runForeground($command, $target->workerDir, $this->childEnv($target));

        return new WranglerResult($command, $result->exitCode, $result->stdout, $result->stderr);
    }

    /**
     * @param list<string> $argv Wrangler arguments, without the binary
     */
    private function run(CloudflareTarget $target, array $argv, ?string $stdin = null): WranglerResult
    {
        $target->assertWorkerDir();

        $binary = WranglerBinary::resolve($this->runner, $target);
        $command = [$binary, ...$argv];

        $result = $this->runner->run(
            $command,
            $target->workerDir,
            $this->childEnv($target),
            self::TIMEOUT_SECONDS,
            $stdin,
        );

        return new WranglerResult($command, $result->exitCode, $result->stdout, $result->stderr);
    }

    /**
     * The child's environment: this process's own, plus the credentials.
     *
     * Inheriting matters — Wrangler needs `PATH` to find `node`, and `HOME` to
     * find an existing `wrangler login` session, which is how a developer with
     * no API token still gets a working `atoms dev`.
     *
     * @return array<string, string>
     */
    private function childEnv(CloudflareTarget $target): array
    {
        $env = getenv();

        // Atoms is scripted; Wrangler's interactive prompts and telemetry pings
        // have no one to answer them.
        $env['CI'] ??= 'true';
        $env['WRANGLER_SEND_METRICS'] ??= 'false';

        return [...$env, ...$target->credentialEnv()];
    }
}
