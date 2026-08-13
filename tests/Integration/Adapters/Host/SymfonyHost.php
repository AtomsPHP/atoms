<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Host;

use Atoms\Client\AtomsConfig;
use Atoms\Tests\Integration\Adapters\Support\FakePsr18Client;
use Atoms\Tests\Integration\Adapters\Support\RecordingLogger;
use Atoms\Tests\Integration\Adapters\Support\RecordingMessageBus;
use Atoms\Tests\Integration\Adapters\Support\TestDoubles;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Boots the committed {@see Host/symfony-app} fixture — real
 * `Atoms\Symfony\AtomsBundle` wiring, mounted the way
 * `packages/symfony/README.md` documents — and drives it through
 * {@see SymfonyTestKernel}'s real HttpKernel/RouterListener/ControllerResolver,
 * never a reimplementation of any of it.
 */
final class SymfonyHost implements AdapterHost
{
    private const ENV_KEYS = [
        'ATOMS_ENDPOINT',
        'ATOMS_API_KEY',
        'ATOMS_PLATFORM_PUBLIC_KEY',
        'ATOMS_CALLBACK_PATH',
    ];

    private ?SymfonyTestKernel $kernel = null;

    private RecordingLogger $logger;

    private FakePsr18Client $http;

    private ?RecordingMessageBus $bus = null;

    private mixed $baselineExceptionHandler = null;

    private mixed $baselineErrorHandler = null;

    public function name(): string
    {
        return 'symfony';
    }

    public function boot(HostOptions $options): void
    {
        $this->logger = new RecordingLogger();
        $this->http = new FakePsr18Client();
        $this->bus = $options->queueAvailable ? new RecordingMessageBus() : null;

        TestDoubles::reset();
        TestDoubles::$client = $this->http;
        TestDoubles::$logger = $this->logger;
        TestDoubles::$nonceStore = $options->nonceStore;
        TestDoubles::$queueAvailable = $options->queueAvailable;
        TestDoubles::$bus = $this->bus;

        // config/packages/atoms.yaml's methods_classes is a fixed list (the
        // ONE Methods class this whole suite ever registers) rather than
        // driven from $options->methodsClasses at runtime: unlike
        // BareKernelHost/PlainPhpHost, which call registerMethodsClass()
        // directly, a Symfony app's methods_classes is compiled config, and
        // $options->methodsClasses never varies across this suite's cases —
        // see CallbackCases and AdapterConformanceTestCase::defaultOptions().
        $this->setEnv('ATOMS_ENDPOINT', $options->endpoint);
        $this->setEnv('ATOMS_PLATFORM_PUBLIC_KEY', $options->publicKey);
        $this->setEnv('ATOMS_CALLBACK_PATH', $options->callbackPath);
        $this->setEnv('ATOMS_API_KEY', $this->apiKeyEnvValue($options->apiKey));

        // Captured so shutdown() can undo exactly what Symfony's own
        // DebugHandlersListener installs on the first handled request,
        // without also ripping out PHPUnit's own handler underneath it.
        $this->baselineExceptionHandler = get_exception_handler();
        $this->baselineErrorHandler = get_error_handler();

        $this->kernel = new SymfonyTestKernel();
        $this->kernel->boot();

        // S2: AtomsConfig::class is a public but lazily-built service — boot()
        // alone never constructs it. Force it now so an empty apiKey's throw
        // surfaces here, matching every other host's "throws at construction"
        // contract, instead of staying latent until something happens to
        // resolve AtomsClient/AtomsConfig later.
        $this->testContainer()->get(AtomsConfig::class);
    }

    public function shutdown(): void
    {
        if ($this->kernel !== null) {
            $varDir = $this->kernel->varDir();
            $this->kernel->shutdown();
            $this->removeDirectory($varDir);
            $this->kernel = null;
        }

        foreach (self::ENV_KEYS as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        TestDoubles::reset();

        // Symfony's DebugHandlersListener installs a global PHP exception
        // (and, depending on config, error) handler on the first handled
        // request and never tears it down on its own — a full Symfony
        // process has no "previous" handler to restore, so it doesn't try.
        // Restore exactly back to what was installed before this host's
        // boot() (captured there), not blindly down to nothing: PHPUnit
        // itself may hold a handler below Symfony's, and ripping that out
        // too is its own "risky" finding ("removed handlers other than its
        // own"). Bounded loops guard against a handler that, for whatever
        // reason, never converges back to the baseline.
        $guard = 0;
        while (get_exception_handler() !== $this->baselineExceptionHandler && $guard++ < 64) {
            restore_exception_handler();
        }

        $guard = 0;
        while (get_error_handler() !== $this->baselineErrorHandler && $guard++ < 64) {
            restore_error_handler();
        }
    }

    public function handle(HostRequest $request): HostResponse
    {
        $server = [];
        foreach ($request->headers as $name => $value) {
            $server[$this->serverKey($name)] = $value;
        }

        $symfonyRequest = Request::create($request->path, $request->method, [], [], [], $server, $request->body);

        /** @var \Symfony\Component\HttpFoundation\Response $response */
        $response = $this->kernel()->handle($symfonyRequest);

        return new HostResponse(
            $response->getStatusCode(),
            $this->flattenHeaders($response->headers->all()),
            (string) $response->getContent(),
        );
    }

    public function service(string $id): object
    {
        /** @var object $service */
        $service = $this->testContainer()->get($id);

        return $service;
    }

    public function queuedJobs(): array
    {
        return $this->bus?->dispatched ?? [];
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
        return in_array($capability, ['routing', 'container', 'client', 'queue', 'logging'], true);
    }

    /**
     * A bare '' is indistinguishable from "unset" to Symfony's `default::`
     * env processor (see config/packages/atoms.yaml's api_key line — both
     * collapse to null, verified against EnvVarProcessor directly). A single
     * space survives `default::`'s emptiness check unchanged and is trimmed
     * back to '' by the outer `trim:` processor before AtomsConfig ever sees
     * it, so S2's misconfiguration reaches AtomsConfig as '', not as a
     * fabricated null.
     */
    private function apiKeyEnvValue(?string $apiKey): ?string
    {
        if ($apiKey === '') {
            return ' ';
        }

        return $apiKey;
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }

    private function kernel(): SymfonyTestKernel
    {
        return $this->kernel ?? throw new \LogicException('SymfonyHost::handle() called before boot().');
    }

    /**
     * With framework.yaml's test:true, 'test.service_container' exposes
     * private services (e.g. AtomsClient::class itself, only reachable
     * normally through the public 'atoms.client' alias) the same way
     * KernelBrowser does — see vendor/symfony/framework-bundle/KernelBrowser.php.
     */
    private function testContainer(): ContainerInterface
    {
        $container = $this->kernel()->getContainer();

        return $container->has('test.service_container') ? $container->get('test.service_container') : $container;
    }

    /**
     * Mirror the real `$_SERVER` shape for one header: uppercase, dashes
     * become underscores, `HTTP_` prefixed — except Content-Type/
     * Content-Length, which PHP exposes unprefixed. Same mapping
     * PlainPhpHost::serverKey() uses, so the M4 lowercase-header case behaves
     * identically here.
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

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $this->removeDirectory($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
