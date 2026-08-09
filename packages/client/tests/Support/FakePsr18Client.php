<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * In-memory PSR-18 double: pop queued responses (or throwables) in order and
 * record every sent request for assertions. Never touches the network.
 */
final class FakePsr18Client implements ClientInterface
{
    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    public function queueResponse(int $status, string $body = '', array $headers = []): self
    {
        $this->queue[] = new Response($status, $headers, $body);

        return $this;
    }

    public function queueJson(int $status, array $payload, array $headers = []): self
    {
        return $this->queueResponse(
            $status,
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES),
            $headers + ['Content-Type' => 'application/json'],
        );
    }

    public function queueThrowable(ClientExceptionInterface $e): self
    {
        $this->queue[] = $e;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->queue === []) {
            throw new \RuntimeException('FakePsr18Client: no queued response for ' . $request->getMethod() . ' ' . $request->getUri());
        }

        $next = array_shift($this->queue);

        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }

    public function lastRequest(): RequestInterface
    {
        if ($this->requests === []) {
            throw new \RuntimeException('No requests recorded.');
        }

        return $this->requests[array_key_last($this->requests)];
    }
}
