<?php

declare(strict_types=1);

namespace Atoms\Client\Tickets;

use Atoms\Client\AtomsConfig;
use Atoms\Client\Exception\AtomsRequestFailed;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;

/**
 * Acquires short-lived WebSocket connection tickets from the platform.
 *
 * EXPERIMENTAL. WebSocket ticketing is not part of the settled contract yet,
 * and no runtime implements the ticket endpoint — the Cloudflare Worker stubs
 * WebSockets entirely. The shape here mirrors the intended
 * `POST /tickets/{type}/{id}` request/response and may change; do not depend on
 * it in production.
 */
final class TicketClient
{
    public function __construct(
        private readonly AtomsConfig $config,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
    ) {
    }

    /**
     * Acquire a connection ticket for the Atom ($type, $id).
     */
    public function acquire(string $type, string $id): string
    {
        $uri = sprintf(
            '%s/tickets/%s/%s',
            $this->config->baseUrl(),
            rawurlencode($type),
            rawurlencode($id),
        );

        $request = $this->requestFactory->createRequest('POST', $uri)
            ->withHeader('Accept', 'application/json');

        // See AtomsConfig::$apiKey: null means "auth is deliberately off".
        if ($this->config->apiKey !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $this->config->apiKey);
        }

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new AtomsRequestFailed(
                'Transport failure acquiring a ticket: ' . $e->getMessage(),
                'transport',
                false,
                0,
                $e,
            );
        }

        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status < 200 || $status >= 300) {
            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];

            throw new AtomsRequestFailed(
                (string) ($error['message'] ?? 'Ticket acquisition failed.'),
                (string) ($error['code'] ?? ''),
                ($error['retryable'] ?? false) === true,
                $status,
            );
        }

        return (string) ($decoded['ticket'] ?? '');
    }
}
