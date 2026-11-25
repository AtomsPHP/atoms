<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal in-memory PSR-18 double: never touches the network. Used to prove
 * DI wiring resolves `atoms.http_client` to a configured service id, and to
 * exercise {@see \Atoms\Client\AtomsClient} calls through the container.
 */
final class FakePsr18Client implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function queueResponse(int $status, string $body = '', array $headers = []): self
    {
        $this->queue[] = new Response($status, $headers, $body);

        return $this;
    }

    /**
     * @param array<string, mixed>               $payload
     * @param array<string, string|list<string>> $headers
     */
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

        if ($this->queue !== []) {
            $next = array_shift($this->queue);

            if ($next instanceof ClientExceptionInterface) {
                throw $next;
            }

            return $next;
        }

        return new Response(200, ['Content-Type' => 'application/json'], '{"result":null}');
    }
}
