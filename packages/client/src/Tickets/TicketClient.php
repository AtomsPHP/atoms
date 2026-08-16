<?php

declare(strict_types=1);

namespace Atoms\Client\Tickets;

use Atoms\Client\AtomsConfig;
use Atoms\Client\Exception\AtomNotDeployed;
use Atoms\Client\Exception\TicketAcquisitionFailed;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Acquires short-lived WebSocket connection tickets from the Worker's
 * `POST /tickets/{type}/{id}` route (mvp-spec §Routing and auth).
 *
 * Browsers cannot set an `Authorization` header on `new WebSocket(url)`, so
 * the application's server — which holds the shared secret and knows who the
 * user is — mints a ticket here and hands it to the browser, which presents
 * it as the `?ticket=` query parameter on the `GET /ws/{type}/{id}` upgrade.
 *
 * $claims are the server-asserted identity channel: a flat string→string map
 * signed into the ticket at mint and merged over the browser's own query
 * params (server wins) before `onConnect()` receives them — so an atom
 * reading `$params['client_id']` gets a value the browser cannot forge. The
 * keys `ticket` and `channels` are reserved and refused by the Worker.
 *
 * Tickets are short-lived and reusable until they expire — the seconds-scale
 * TTL is the defense against a leaked URL, so a reconnect inside the TTL may
 * retry the same URL. On ANY connection failure, mint a fresh one: a browser
 * cannot read the HTTP status or body of a failed WebSocket upgrade, so every
 * ticket refusal (invalid, expired) surfaces identically as an opaque
 * connection failure and there is nothing to branch on browser-side.
 *
 * The mint request carries the same derived bearer as an invocation
 * ({@see AtomsConfig::bearerToken()}). Every ticket the Worker mints is
 * signed with a key derived from the same shared secret, so local dev and
 * production run identical code paths.
 */
final class TicketClient
{
    /** @var callable(int): void */
    private $sleep;

    /** @var callable(int): string */
    private $idGenerator;

    /**
     * @param callable(int): void|null   $sleep       Receives a delay in milliseconds; defaults to usleep().
     * @param callable(int): string|null $idGenerator Receives a byte count, returns that many random bytes; for tests.
     */
    public function __construct(
        private readonly AtomsConfig $config,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        ?callable $sleep = null,
        ?callable $idGenerator = null,
    ) {
        $this->sleep = $sleep ?? static function (int $ms): void {
            if ($ms > 0) {
                usleep($ms * 1000);
            }
        };
        $this->idGenerator = $idGenerator ?? static fn (int $bytes): string => random_bytes($bytes);
    }

    /**
     * Mint a connection ticket for the Atom ($type, $id).
     *
     * @param array<string, string> $claims Server-asserted values merged over
     *                                      the browser's query params at
     *                                      connect time, server wins.
     */
    public function acquire(string $type, string $id, array $claims = []): Ticket
    {
        $uri = sprintf(
            '%s/tickets/%s/%s',
            $this->config->baseUrl(),
            rawurlencode($type),
            rawurlencode($id),
        );

        $request = $this->requestFactory->createRequest('POST', $uri)
            ->withHeader('Accept', 'application/json')
            ->withHeader('traceparent', $this->generateTraceparent())
            ->withHeader('Authorization', 'Bearer ' . $this->config->bearerToken());

        if ($claims !== []) {
            $body = json_encode(['claims' => $claims], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($body));
        }

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->http->sendRequest($request);
            } catch (NetworkExceptionInterface $e) {
                if ($attempt < $this->config->maxAttempts) {
                    $this->backoff($attempt);
                    continue;
                }

                throw new TicketAcquisitionFailed(
                    $type,
                    $id,
                    'transport failure: ' . $e->getMessage(),
                    'transport',
                    false,
                    0,
                    $e,
                );
            } catch (ClientExceptionInterface $e) {
                throw new TicketAcquisitionFailed(
                    $type,
                    $id,
                    'transport failure: ' . $e->getMessage(),
                    'transport',
                    false,
                    0,
                    $e,
                );
            }

            $status = $response->getStatusCode();
            $decoded = $this->decodeBody((string) $response->getBody());

            if ($status >= 200 && $status < 300) {
                $ticket = $decoded['ticket'] ?? null;
                $expiresAt = $decoded['expires_at'] ?? null;
                if (!is_string($ticket) || $ticket === '' || !is_int($expiresAt)) {
                    throw new TicketAcquisitionFailed(
                        $type,
                        $id,
                        'the mint response is malformed (missing "ticket" or "expires_at")',
                        '',
                        false,
                        $status,
                    );
                }

                return new Ticket($ticket, $expiresAt);
            }

            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            $code = (string) ($error['code'] ?? '');
            $retryable = ($error['retryable'] ?? false) === true;

            if ($retryable && $attempt < $this->config->maxAttempts) {
                $this->backoff($attempt);
                continue;
            }

            if ($code === 'unknown_atom_type') {
                throw new AtomNotDeployed($type, $status);
            }

            throw new TicketAcquisitionFailed(
                $type,
                $id,
                (string) ($error['message'] ?? sprintf('the platform answered %d', $status)),
                $code,
                $retryable,
                $status,
            );
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeBody(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function backoff(int $attempt): void
    {
        $base = $this->config->backoffBaseMs * (2 ** ($attempt - 1));
        $base = max(1, $base);

        $delay = $this->config->backoffJitter ? random_int((int) ceil($base / 2), $base) : $base;

        ($this->sleep)($delay);
    }

    private function generateTraceparent(): string
    {
        $traceId = bin2hex(($this->idGenerator)(16));
        $parentId = bin2hex(($this->idGenerator)(8));

        return sprintf('00-%s-%s-01', $traceId, $parentId);
    }
}
