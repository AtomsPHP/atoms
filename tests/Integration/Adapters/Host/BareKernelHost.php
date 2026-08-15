<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Host;

use Atoms\Client\Callback\CallbackKernel;
use Atoms\Client\Callback\CallbackKernelFactory;
use Atoms\Client\Callback\MethodsResolver;
use Atoms\Client\Callback\NullQueueBridge;
use Atoms\Tests\Integration\Adapters\Support\ArrayContainer;
use Atoms\Tests\Integration\Adapters\Support\FakePsr18Client;
use Atoms\Tests\Integration\Adapters\Support\RecordingLogger;
use Atoms\Tests\Integration\Adapters\Support\RecordingQueueBridge;
use GuzzleHttp\Psr7\HttpFactory;

/**
 * The framework-free floor: {@see CallbackKernelFactory::create()} wired
 * directly, with no router, no outbound client, and no framework container —
 * the reference host every case in the conformance table is developed and
 * verified against first. It DOES support the `container` capability:
 * {@see CallbackKernelFactory::create()} already accepts a plain PSR-11
 * `container:` parameter, so this host can thread a stub {@see ArrayContainer}
 * through it (see S6) without needing a real framework's DI container.
 */
final class BareKernelHost implements AdapterHost
{
    private CallbackKernel $kernel;

    private RecordingQueueBridge $queue;

    private RecordingLogger $logger;

    private FakePsr18Client $http;

    private HttpFactory $factory;

    public function name(): string
    {
        return 'bare-kernel';
    }

    public function boot(HostOptions $options): void
    {
        $this->factory = new HttpFactory();
        $this->queue = new RecordingQueueBridge();
        $this->logger = new RecordingLogger();
        $this->http = new FakePsr18Client();

        $resolver = new MethodsResolver();
        foreach ($options->methodsClasses as $methodsClass) {
            $resolver->registerMethodsClass($methodsClass);
        }

        $this->kernel = CallbackKernelFactory::create(
            $options->sharedSecret,
            $this->factory,
            $this->factory,
            $options->sharedSecretPrevious,
            queueBridge: $options->queueAvailable ? $this->queue : new NullQueueBridge('bare host has no queue'),
            resolver: $resolver,
            nonceStore: $options->nonceStore,
            // S6: a stub PSR-11 container carrying whatever pre-built
            // instances this boot's HostOptions supplied. Empty by default,
            // in which case container->has() is false for everything and
            // instantiate() falls through to `new $class()` exactly as
            // before this field existed — see ArrayContainer.
            container: new ArrayContainer($options->containerBindings),
            logger: $this->logger,
        );
    }

    public function shutdown(): void
    {
        // Nothing opened beyond in-process fakes: no file handles, no sockets.
    }

    public function handle(HostRequest $request): HostResponse
    {
        $psrRequest = $this->factory->createServerRequest($request->method, 'https://bare-host.test' . $request->path);

        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }

        $psrRequest = $psrRequest->withBody($this->factory->createStream($request->body));

        $response = $this->kernel->handle($psrRequest);

        return new HostResponse(
            $response->getStatusCode(),
            $this->flattenHeaders($response->getHeaders()),
            (string) $response->getBody(),
        );
    }

    public function service(string $id): object
    {
        throw new \LogicException(
            'BareKernelHost has no container or client: ' . $id . ' is not resolvable. Check supports() first.',
        );
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
        return in_array($capability, ['queue', 'logging', 'container'], true);
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
