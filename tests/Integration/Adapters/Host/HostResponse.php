<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Host;

/**
 * What {@see AdapterHost::handle()} produced, normalized away from whatever
 * response type the host's own stack returns (PSR-7 ResponseInterface today;
 * a framework's HTTP response type for T9b's hosts).
 */
final readonly class HostResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {
    }
}
