<?php

declare(strict_types=1);

namespace Atoms\Cli\Platform;

/**
 * The deploy-plane HTTP surface the CLI speaks (docs/platform/api-contract.md).
 * Implemented for real by {@see CurlPlatformApi}; tests inject a fake so the
 * suite never touches the network.
 */
interface PlatformApi
{
    /**
     * POST /v1/{project}/deploys — Content-Type: application/gzip.
     */
    public function deploy(PlatformTarget $target, string $gzipPath): HttpResponse;

    /**
     * GET /v1/{project}/deploys.
     */
    public function deploys(PlatformTarget $target): HttpResponse;

    /**
     * POST /v1/{project}/rollback — body {"version": "..."} (omit for previous).
     */
    public function rollback(PlatformTarget $target, ?string $version): HttpResponse;

    /**
     * POST /v1/{project}/secrets — experimental (integration-plan §4.5).
     */
    public function setSecret(PlatformTarget $target, string $key, string $value): HttpResponse;

    /**
     * GET /v1/{project}/secrets — experimental.
     */
    public function listSecrets(PlatformTarget $target): HttpResponse;
}
