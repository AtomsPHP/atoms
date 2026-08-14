<?php

declare(strict_types=1);

namespace Atoms\Symfony\Controller;

use Atoms\Client\Callback\CallbackKernel;
use Psr\Http\Message\ResponseInterface as PsrResponse;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface as PsrServerRequest;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mounts atoms/client's PSR-15 {@see CallbackKernel} as a Symfony controller
 * without a bridge dependency: manual Request <-> PSR-7 conversion against
 * the plain PSR-17 interfaces (ServerRequestFactoryInterface,
 * StreamFactoryInterface) rather than psr/http-message-bridge, which would be
 * one more package pulled in purely to save ~30 lines. The concrete factory
 * behind those interfaces is whatever the bundle resolved `atoms.psr17_factory`
 * to (config's `psr17_factory` key, an app-defined service, or Guzzle by
 * default — see AtomsBundle::registerHttpClient() and Psr17FactoryPass), so
 * this controller stays implementation-agnostic. Routed automatically via
 * {@see \Atoms\Symfony\Routing\AtomsRouteLoader} — see the package README.
 */
final class CallbackController
{
    public function __construct(
        private readonly CallbackKernel $kernel,
        private readonly ServerRequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $psrResponse = $this->kernel->handle($this->toPsrRequest($request));

        return $this->toHttpFoundationResponse($psrResponse);
    }

    private function toPsrRequest(Request $request): PsrServerRequest
    {
        $psrRequest = $this->requestFactory->createServerRequest(
            $request->getMethod(),
            $request->getUri(),
            $request->server->all(),
        );

        foreach ($request->headers->all() as $name => $values) {
            $psrRequest = $psrRequest->withHeader($name, array_map(strval(...), $values));
        }

        return $psrRequest->withBody($this->streamFactory->createStream($request->getContent()));
    }

    private function toHttpFoundationResponse(PsrResponse $psrResponse): Response
    {
        $response = new Response((string) $psrResponse->getBody(), $psrResponse->getStatusCode());

        foreach ($psrResponse->getHeaders() as $name => $values) {
            $response->headers->set($name, $values);
        }

        return $response;
    }
}
