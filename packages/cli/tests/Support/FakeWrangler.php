<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Support;

use Atoms\Cli\Cloudflare\CloudflareTarget;
use Atoms\Cli\Cloudflare\Wrangler;
use Atoms\Cli\Cloudflare\WranglerResult;

/**
 * In-memory {@see Wrangler}: records every call and answers with canned
 * results. No subprocess, no Cloudflare, no network.
 */
final class FakeWrangler implements Wrangler
{
    /** @var list<array{method: string, target: CloudflareTarget, args: array<string, mixed>}> */
    public array $calls = [];

    public ?WranglerResult $deployResult = null;
    public ?WranglerResult $versionsResult = null;
    public ?WranglerResult $rollbackResult = null;
    public ?WranglerResult $putSecretResult = null;
    public ?WranglerResult $listSecretsResult = null;
    public ?WranglerResult $devResult = null;

    public function deploy(CloudflareTarget $target, array $vars = []): WranglerResult
    {
        $this->record('deploy', $target, ['vars' => $vars]);

        return $this->deployResult ?? self::ok(['deploy'], "Deployed {$target->workerName}\n");
    }

    public function versions(CloudflareTarget $target): WranglerResult
    {
        $this->record('versions', $target, []);

        return $this->versionsResult ?? self::ok(['versions', 'list'], '[]');
    }

    public function rollback(CloudflareTarget $target, ?string $versionId, ?string $message): WranglerResult
    {
        $this->record('rollback', $target, ['versionId' => $versionId, 'message' => $message]);

        return $this->rollbackResult ?? self::ok(['rollback'], '');
    }

    public function putSecret(CloudflareTarget $target, string $key, string $value): WranglerResult
    {
        $this->record('putSecret', $target, ['key' => $key, 'value' => $value]);

        return $this->putSecretResult ?? self::ok(['secret', 'put'], '');
    }

    public function listSecrets(CloudflareTarget $target): WranglerResult
    {
        $this->record('listSecrets', $target, []);

        return $this->listSecretsResult ?? self::ok(['secret', 'list'], '[]');
    }

    public function dev(CloudflareTarget $target, string $port, array $vars): WranglerResult
    {
        $this->record('dev', $target, ['port' => $port, 'vars' => $vars]);

        return $this->devResult ?? self::ok(['dev'], '');
    }

    /**
     * The recorded call for $method, or null if it was never made.
     *
     * @return array{method: string, target: CloudflareTarget, args: array<string, mixed>}|null
     */
    public function lastCall(string $method): ?array
    {
        for ($i = \count($this->calls) - 1; $i >= 0; $i--) {
            if ($this->calls[$i]['method'] === $method) {
                return $this->calls[$i];
            }
        }

        return null;
    }

    /**
     * @param list<string> $argv
     */
    public static function ok(array $argv, string $stdout): WranglerResult
    {
        return new WranglerResult(['wrangler', ...$argv], 0, $stdout, '');
    }

    /**
     * @param list<string> $argv
     */
    public static function failed(array $argv, string $stderr, int $exitCode = 1): WranglerResult
    {
        return new WranglerResult(['wrangler', ...$argv], $exitCode, '', $stderr);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function record(string $method, CloudflareTarget $target, array $args): void
    {
        $this->calls[] = ['method' => $method, 'target' => $target, 'args' => $args];
    }
}
