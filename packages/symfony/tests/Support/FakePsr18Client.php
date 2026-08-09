<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal in-memory PSR-18 double: never touches the network. Used to prove
 * DI wiring resolves `atoms.http_client` to a configured service id.
 */
final class FakePsr18Client implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return new Response(200, ['Content-Type' => 'application/json'], '{"result":null}');
    }
}
