<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Host;

/**
 * A callback request to run through {@see AdapterHost::handle()}, host-shape
 * agnostic: each host maps this to whatever its real stack actually consumes
 * (a PSR-7 ServerRequest for the bare kernel, a `$_SERVER`-shaped array for
 * the plain-PHP example, an HTTP kernel request for a framework host).
 */
final readonly class HostRequest
{
    /**
     * @param array<string, string> $headers Header name => value. Header
     *        lookup by the underlying stack may or may not be case-sensitive
     *        depending on the host — that is precisely what the M4
     *        lowercase-header case exercises.
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $headers,
        public string $body,
    ) {
    }
}
