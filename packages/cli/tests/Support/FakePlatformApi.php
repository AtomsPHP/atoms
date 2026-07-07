<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Support;

use Atoms\Cli\Platform\HttpResponse;
use Atoms\Cli\Platform\PlatformApi;
use Atoms\Cli\Platform\PlatformTarget;

/**
 * In-memory PlatformApi for command tests — records calls and returns canned
 * responses. No network.
 */
final class FakePlatformApi implements PlatformApi
{
    /** @var list<array{method: string, args: array<string, mixed>}> */
    public array $calls = [];

    public function __construct(
        public HttpResponse $deployResponse = new HttpResponse(201, ['version' => 'v4']),
        public HttpResponse $deploysResponse = new HttpResponse(200, ['versions' => ['v1', 'v2'], 'current_version' => 'v2']),
        public HttpResponse $rollbackResponse = new HttpResponse(200, ['current_version' => 'v1']),
        public HttpResponse $setSecretResponse = new HttpResponse(200, ['ok' => true]),
        public HttpResponse $listSecretsResponse = new HttpResponse(200, ['keys' => ['STRIPE_KEY']]),
    ) {
    }

    public function deploy(PlatformTarget $target, string $gzipPath): HttpResponse
    {
        $this->calls[] = ['method' => 'deploy', 'args' => ['project' => $target->project, 'gzip' => $gzipPath]];

        return $this->deployResponse;
    }

    public function deploys(PlatformTarget $target): HttpResponse
    {
        $this->calls[] = ['method' => 'deploys', 'args' => ['project' => $target->project]];

        return $this->deploysResponse;
    }

    public function rollback(PlatformTarget $target, ?string $version): HttpResponse
    {
        $this->calls[] = ['method' => 'rollback', 'args' => ['version' => $version]];

        return $this->rollbackResponse;
    }

    public function setSecret(PlatformTarget $target, string $key, string $value): HttpResponse
    {
        $this->calls[] = ['method' => 'setSecret', 'args' => ['key' => $key, 'value' => $value]];

        return $this->setSecretResponse;
    }

    public function listSecrets(PlatformTarget $target): HttpResponse
    {
        $this->calls[] = ['method' => 'listSecrets', 'args' => ['project' => $target->project]];

        return $this->listSecretsResponse;
    }
}
