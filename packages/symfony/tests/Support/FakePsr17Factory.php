<?php

declare(strict_types=1);

namespace Atoms\Symfony\Tests\Support;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A non-Guzzle PSR-17 factory implementing the four core factory interfaces
 * (request, response, server request, stream) — the shape an app that has
 * e.g. nyholm/psr7 autowired might expose. Never actually invoked: it exists
 * so Psr17FactoryPassTest/AtomsBundleExtensionTest can register it under
 * those interface ids and prove the bundle does *not* auto-detect it —
 * see Psr17FactoryPass, which resolves `atoms.psr17_factory` only from the
 * configured `psr17_factory` service id or the bundled Guzzle default.
 */
final class FakePsr17Factory implements
    RequestFactoryInterface,
    ResponseFactoryInterface,
    ServerRequestFactoryInterface,
    StreamFactoryInterface
{
    public function createRequest(string $method, $uri): RequestInterface
    {
        throw new \LogicException('FakePsr17Factory is an identity double; it is never actually invoked.');
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        throw new \LogicException('FakePsr17Factory is an identity double; it is never actually invoked.');
    }

    /**
     * @param array<string, mixed> $serverParams
     */
    public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface
    {
        throw new \LogicException('FakePsr17Factory is an identity double; it is never actually invoked.');
    }

    public function createStream(string $content = ''): StreamInterface
    {
        throw new \LogicException('FakePsr17Factory is an identity double; it is never actually invoked.');
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        throw new \LogicException('FakePsr17Factory is an identity double; it is never actually invoked.');
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        throw new \LogicException('FakePsr17Factory is an identity double; it is never actually invoked.');
    }
}
