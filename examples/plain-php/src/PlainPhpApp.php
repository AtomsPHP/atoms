<?php

declare(strict_types=1);

namespace Atoms\Examples\PlainPhp;

use Atoms\Client\AtomsClient;
use Atoms\Client\Callback\CallbackKernel;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The tiny amount of framework role a plain-PHP host still needs, built by
 * {@see AtomsBootstrap::create()}: routing one path to the
 * {@see CallbackKernel}, and an {@see AtomsClient} for the rest of the app.
 * Everything a router (Slim, Mezzio) or a front controller (vanilla PHP)
 * would otherwise hand-roll for this one endpoint.
 */
final class PlainPhpApp
{
    public function __construct(
        private readonly AtomsClient $client,
        private readonly CallbackKernel $kernel,
        private readonly string $callbackPath,
        private readonly ServerRequestFactoryInterface $serverRequestFactory,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function client(): AtomsClient
    {
        return $this->client;
    }

    public function kernel(): CallbackKernel
    {
        return $this->kernel;
    }

    /**
     * The framework-role logic a router would normally do: reject anything
     * that is not a POST to the configured callback path, then delegate to
     * the kernel, which does the real (signature, replay, dispatch) work.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->plainText(405, 'Method Not Allowed');
        }

        if ($request->getUri()->getPath() !== $this->callbackPath) {
            return $this->plainText(404, 'Not Found');
        }

        return $this->kernel->handle($request);
    }

    /**
     * Build a {@see ServerRequestInterface} from a `$_SERVER`-shaped array and
     * a raw request body, then {@see self::handle()} it. This is the seam a
     * vanilla front controller uses (see `public/atoms-callback.php`); a
     * micro-framework calls {@see self::handle()} directly with the
     * ServerRequest it already built.
     *
     * The body is passed through byte-for-byte — no re-encoding — because the
     * signature in `X-Atoms-Signature` covers the exact bytes the platform
     * sent.
     *
     * @param array<string, mixed> $server
     */
    public function handleGlobals(array $server, string $body): ResponseInterface
    {
        $method = (string) ($server['REQUEST_METHOD'] ?? 'GET');
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = explode('?', $uri, 2)[0];

        $request = $this->serverRequestFactory->createServerRequest($method, $path, $server);

        foreach ($this->headersFromServer($server) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $request = $request->withBody($this->streamFactory->createStream($body));

        return $this->handle($request);
    }

    /**
     * Recover HTTP headers from a `$_SERVER`-shaped array: `HTTP_*` entries
     * (e.g. `HTTP_X_ATOMS_SIGNATURE`) plus the two CGI headers PHP exposes
     * without the `HTTP_` prefix, `CONTENT_TYPE` and `CONTENT_LENGTH`.
     *
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private function headersFromServer(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $headers[$this->headerName(substr($key, 5))] = (string) $value;
                continue;
            }

            if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[$this->headerName($key)] = (string) $value;
            }
        }

        return $headers;
    }

    private function headerName(string $serverKey): string
    {
        return str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower($serverKey))));
    }

    private function plainText(int $status, string $body): ResponseInterface
    {
        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withBody($this->streamFactory->createStream($body));
    }
}
