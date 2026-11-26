<?php

declare(strict_types=1);

namespace Atoms\Http;

final readonly class HttpResponse
{
    /** @var array<string, list<string>> */
    public array $headers;

    /** @param array<string, list<string>> $headers */
    public function __construct(public int $status, array $headers = [], public string $body = '')
    {
        $normalized = [];
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $normalized[strtolower((string) $name)][] = $value;
            }
        }
        $this->headers = $normalized;
    }
}
