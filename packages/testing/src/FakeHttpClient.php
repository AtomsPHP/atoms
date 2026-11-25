<?php

declare(strict_types=1);

namespace Atoms\Testing;

use Atoms\Errors\ErrorCode;
use Atoms\Http\HttpClient;
use Atoms\Http\HttpRequest;
use Atoms\Http\HttpResponse;

/** @internal One ordered script per harness, including lifecycle calls. */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{method: string, url: string, headers: list<array{string, string}>, body: ?string}> */
    private array $requests = [];

    /** @param list<array{string, string, HttpResponse|\Throwable}> $script */
    public function __construct(private readonly \PDO $pdo, private array $script)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        if ($this->pdo->inTransaction()) {
            throw HttpRequest::failure(ErrorCode::HttpInTransaction);
        }
        $pairs = HttpRequest::pairs($method, $url, $headers, $body, PHP_INT_MAX);
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $pairs, 'body' => $body];
        $next = $this->script[0] ?? null;
        if ($next === null || $next[0] !== $method || $next[1] !== $url) {
            throw HttpRequest::failure(ErrorCode::HttpUnstubbed);
        }
        array_shift($this->script);
        if ($next[2] instanceof \Throwable) {
            throw $next[2];
        }
        return $next[2];
    }

    /** @return list<array{method: string, url: string, headers: list<array{string, string}>, body: ?string}> */
    public function requests(): array
    {
        return $this->requests;
    }
}
