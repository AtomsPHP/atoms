<?php

declare(strict_types=1);

namespace Atoms\Http;

interface HttpClient
{
    /** @param array<string, string|list<string>> $headers */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}
