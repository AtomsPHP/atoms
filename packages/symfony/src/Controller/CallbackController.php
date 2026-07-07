<?php

declare(strict_types=1);

namespace Atoms\Symfony\Controller;

use Atoms\Client\Callback\CallbackKernel;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Message\ResponseInterface as PsrResponse;
use Psr\Http\Message\ServerRequestInterface as PsrServerRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal Phase 1 layering test — not yet a supported product
 *
 * Mounts atoms/client's PSR-15 {@see CallbackKernel} as a Symfony controller
 * without a bridge dependency: manual Request <-> PSR-7 conversion via
 * guzzlehttp/psr7 (the same library the bundle already relies on for its
 * PSR-17 default), since psr/http-message-bridge would be one more package
 * pulled in purely to save ~30 lines. Not routed automatically — see
 * Resources/config/routes.php and the package README.
 */
final class CallbackController
{
    public function __construct(
        private readonly CallbackKernel $kernel,
        private readonly HttpFactory $psrFactory = new HttpFactory(),
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $psrResponse = $this->kernel->handle($this->toPsrRequest($request));

        return $this->toHttpFoundationResponse($psrResponse);
    }

    private function toPsrRequest(Request $request): PsrServerRequest
    {
        $psrRequest = $this->psrFactory->createServerRequest(
            $request->getMethod(),
            $request->getUri(),
            $request->server->all(),
        );

        foreach ($request->headers->all() as $name => $values) {
            $psrRequest = $psrRequest->withHeader($name, array_map(strval(...), $values));
        }

        return $psrRequest->withBody($this->psrFactory->createStream($request->getContent()));
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
