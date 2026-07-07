<?php

declare(strict_types=1);

namespace Atoms\Laravel\Http;

use Atoms\Client\Callback\CallbackKernel;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Mounts {@see CallbackKernel} (pure PSR-15) as the route handler for the
 * inbound platform callback. Converts Illuminate's Request to a PSR-7
 * ServerRequest and the PSR-7 Response back to an Illuminate one; no other
 * logic lives here by design (that's the point of the kernel being framework
 * free) — see docs/integration-plan.md §5.1.
 */
final class CallbackController
{
    public function __construct(private readonly CallbackKernel $kernel)
    {
    }

    public function __invoke(Request $request): Response
    {
        $psrRequest = new ServerRequest(
            $request->method(),
            $request->fullUrl(),
            $request->headers->all(),
            $request->getContent(),
            $request->server->get('SERVER_PROTOCOL') === 'HTTP/2' ? '2' : '1.1',
        );

        $psrResponse = $this->kernel->handle($psrRequest);

        return response(
            (string) $psrResponse->getBody(),
            $psrResponse->getStatusCode(),
            $psrResponse->getHeaders(),
        );
    }
}
