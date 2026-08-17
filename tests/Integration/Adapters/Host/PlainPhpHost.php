<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Host;

use Atoms\Client\AtomsClient;
use Atoms\Client\Tickets\TicketIssuer;
use Atoms\Client\Callback\MethodsResolver;
use Atoms\Client\Callback\NullQueueBridge;
use Atoms\Examples\PlainPhp\AtomsBootstrap;
use Atoms\Examples\PlainPhp\PlainPhpApp;
use Atoms\Tests\Integration\Adapters\Support\ArrayContainer;
use Atoms\Tests\Integration\Adapters\Support\FakePsr18Client;
use Atoms\Tests\Integration\Adapters\Support\RecordingLogger;
use Atoms\Tests\Integration\Adapters\Support\RecordingQueueBridge;
use GuzzleHttp\Psr7\HttpFactory;

/**
 * Boots the plain-PHP example's own {@see AtomsBootstrap::create()} and
 * drives it through {@see PlainPhpApp::handleGlobals()} — the exact
 * front-controller path `examples/plain-php/public/atoms-callback.php` uses
 * — so this suite exercises the example's real code (see
 * `examples/AGENTS.md`), never a reimplementation of it.
 */
final class PlainPhpHost implements AdapterHost
{
    private PlainPhpApp $app;

    private RecordingQueueBridge $queue;

    private RecordingLogger $logger;

    private FakePsr18Client $http;

    public function name(): string
    {
        return 'plain-php';
    }

    public function boot(HostOptions $options): void
    {
        $factory = new HttpFactory();
        $this->queue = new RecordingQueueBridge();
        $this->logger = new RecordingLogger();
        $this->http = new FakePsr18Client();

        $resolver = new MethodsResolver();
        foreach ($options->methodsClasses as $methodsClass) {
            $resolver->registerMethodsClass($methodsClass);
        }

        $this->app = AtomsBootstrap::create(
            endpoint: $options->endpoint,
            sharedSecret: $options->sharedSecret,
            callbackPath: $options->callbackPath,
            http: $this->http,
            requestFactory: $factory,
            serverRequestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
            queueBridge: $options->queueAvailable ? $this->queue : new NullQueueBridge('plain-php host has no queue'),
            resolver: $resolver,
            nonceStore: $options->nonceStore,
            sharedSecretPrevious: $options->sharedSecretPrevious,
            logger: $this->logger,
            // S6: see BareKernelHost's identical comment — empty by default,
            // in which case behavior is unchanged from before this field
            // existed.
            container: new ArrayContainer($options->containerBindings),
        );
    }

    public function shutdown(): void
    {
        // Nothing opened beyond in-process fakes: no file handles, no sockets.
    }

    public function handle(HostRequest $request): HostResponse
    {
        $server = [
            'REQUEST_METHOD' => $request->method,
            'REQUEST_URI' => $request->path,
        ];

        foreach ($request->headers as $name => $value) {
            $server[$this->serverKey($name)] = $value;
        }

        $response = $this->app->handleGlobals($server, $request->body);

        return new HostResponse(
            $response->getStatusCode(),
            $this->flattenHeaders($response->getHeaders()),
            (string) $response->getBody(),
        );
    }

    public function service(string $id): object
    {
        if ($id === AtomsClient::class) {
            return $this->app->client();
        }

        if ($id === TicketIssuer::class) {
            return $this->app->tickets();
        }

        throw new \LogicException('PlainPhpHost has no service registered for ' . $id . '.');
    }

    public function queuedJobs(): array
    {
        return $this->queue->jobs;
    }

    public function logRecords(): array
    {
        return $this->logger->records;
    }

    public function httpFake(): FakePsr18Client
    {
        return $this->http;
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, ['routing', 'client', 'queue', 'logging', 'container'], true);
    }

    /**
     * Mirror the real `$_SERVER` shape for one header: uppercase, dashes
     * become underscores, `HTTP_` prefixed — except Content-Type/
     * Content-Length, which PHP exposes unprefixed. This is the inverse of
     * {@see PlainPhpApp::headersFromServer()}. Uppercasing here is also what
     * makes the M4 lowercase-header case pass: a lowercase `x-atoms-kind`
     * collapses to the same `HTTP_X_ATOMS_KIND` key as the canonical case.
     */
    private function serverKey(string $headerName): string
    {
        $normalized = strtoupper(str_replace('-', '_', $headerName));

        return match ($normalized) {
            'CONTENT_TYPE', 'CONTENT_LENGTH' => $normalized,
            default => 'HTTP_' . $normalized,
        };
    }

    /**
     * @param array<string, list<string>> $headers
     * @return array<string, string>
     */
    private function flattenHeaders(array $headers): array
    {
        $flat = [];
        foreach ($headers as $name => $values) {
            $flat[$name] = implode(', ', $values);
        }

        return $flat;
    }
}
