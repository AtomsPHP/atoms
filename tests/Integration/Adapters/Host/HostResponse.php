<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Host;

/**
 * What {@see AdapterHost::handle()} produced, normalized away from whatever
 * response type the host's own stack returns (PSR-7 ResponseInterface for
 * the bare kernel; a framework's own HTTP response type for Laravel and
 * Symfony).
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
