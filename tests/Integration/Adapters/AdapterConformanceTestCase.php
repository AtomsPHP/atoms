<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters;

use Atoms\Client\AtomsClient;
use Atoms\Tests\Integration\Adapters\Fixtures\GameRoom;
use Atoms\Tests\Integration\Adapters\Fixtures\GameRoom\Methods as GameRoomMethods;
use Atoms\Tests\Integration\Adapters\Fixtures\RankRoom\MethodsWithDependency as RankRoomMethods;
use Atoms\Tests\Integration\Adapters\Fixtures\RecordScoreJob;
use Atoms\Tests\Integration\Adapters\Fixtures\Scoreboard;
use Atoms\Tests\Integration\Adapters\Host\AdapterHost;
use Atoms\Tests\Integration\Adapters\Host\HostOptions;
use Atoms\Tests\Integration\Adapters\Host\HostRequest;
use Atoms\Tests\Integration\Adapters\Support\CallbackSigner;
use Atoms\Tests\Integration\Adapters\Support\RecordingNonceStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The adapter conformance suite itself. One case table
 * ({@see CallbackCases::all()}), one set of dedicated mounting/supply-chain
 * tests, run unmodified against every {@see AdapterHost} a concrete subclass
 * supplies via {@see self::createHost()}.
 *
 * T9a ships two concrete subclasses (bare kernel, plain-PHP). T9b is expected
 * to add Laravel/Symfony subclasses of this SAME class, plus a cross-host
 * equivalence test — nothing here should need to change for that.
 *
 * §7/round-2 item 4: every capability gate in this class (the named M/S
 * tests' {@see self::skipUnlessSupports()} calls, and {@see
 * self::testCallbackCase()}'s own loop over {@see CallbackCase::$appliesTo})
 * used to turn ANY unsupported capability straight into a skip, with nothing
 * distinguishing a host's genuine, permanent gap from one it silently lost —
 * proven empirically: removing 'container' from LaravelHost::supports()
 * left the whole suite green, just quietly skipping one more test (7→8).
 * {@see self::expectedMissingCapabilities()} is this class's counterpart to
 * {@see CrossHostEquivalenceTest::EXPECTED_SKIPS}: each concrete subclass
 * declares exactly which capabilities its host is expected to lack, and
 * {@see self::failOrSkipMissingCapability()} fails — rather than skips — the
 * moment a host reports a gap that declaration doesn't name.
 */
abstract class AdapterConformanceTestCase extends TestCase
{
    protected AdapterHost $host;

    protected CallbackSigner $signer;

    protected HostOptions $options;

    abstract protected function createHost(): AdapterHost;

    /**
     * The declared counterpart to {@see AdapterHost::supports()}: every
     * capability this concrete subclass's host is expected to permanently
     * lack. A capability absent from both `supports()` and this list is
     * treated as a failure, not a skip — see the class docblock and {@see
     * self::failOrSkipMissingCapability()}.
     *
     * @return list<string>
     */
    abstract protected function expectedMissingCapabilities(): array;

    protected function setUp(): void
    {
        $this->signer = new CallbackSigner();
        $this->host = $this->createHost();
        $this->options = $this->defaultOptions();
        $this->host->boot($this->options);
    }

    protected function tearDown(): void
    {
        $this->host->shutdown();
    }

    /**
     * @param array{endpoint?: string, apiKey?: ?string, publicKey?: string, callbackPath?: string, methodsClasses?: list<class-string>, nonceStore?: ?\Atoms\Client\Callback\NonceStore, queueAvailable?: bool, containerBindings?: array<class-string, object>} $overrides
     */
    protected function defaultOptions(array $overrides = []): HostOptions
    {
        return new HostOptions(
            endpoint: $overrides['endpoint'] ?? 'http://worker.test',
            apiKey: array_key_exists('apiKey', $overrides) ? $overrides['apiKey'] : 'k',
            publicKey: $overrides['publicKey'] ?? $this->signer->publicKeyBase64(),
            callbackPath: $overrides['callbackPath'] ?? '/atoms/callback',
            methodsClasses: $overrides['methodsClasses'] ?? [GameRoomMethods::class],
            nonceStore: array_key_exists('nonceStore', $overrides) ? $overrides['nonceStore'] : null,
            queueAvailable: $overrides['queueAvailable'] ?? true,
            containerBindings: $overrides['containerBindings'] ?? [],
        );
    }

    /**
     * @return iterable<string, array{CallbackCase}>
     */
    public static function callbackCases(): iterable
    {
        foreach (CallbackCases::all() as $case) {
            yield $case->key => [$case];
        }
    }

    #[DataProvider('callbackCases')]
    public function testCallbackCase(CallbackCase $case): void
    {
        foreach ($case->appliesTo as $capability) {
            if (!$this->host->supports($capability)) {
                $this->failOrSkipMissingCapability($capability, "case '{$case->key}'");
            }
        }

        $request = ($case->build)($this->signer, $this->options);

        if ($case->primeFirst) {
            $this->host->handle($request);
        }

        $response = $this->host->handle($request);

        self::assertSame($case->expectedStatus, $response->status, "case '{$case->key}': status");

        match ($case->bodyAssertion) {
            'exact' => self::assertSame($case->expectedBody, $response->body, "case '{$case->key}': body"),
            'jsonCode' => $this->assertJsonCode($case, $response->body),
        };

        if ($case->expectQueuedJob) {
            self::assertNotEmpty($this->host->queuedJobs(), "case '{$case->key}': expected a queued job");
        }

        if ($case->expectLogError) {
            self::assertNotEmpty($this->errorLogRecords(), "case '{$case->key}': expected an error log record");
        }
    }

    /**
     * M1: a GET at the callback path is rejected before it ever reaches the
     * kernel. Routing-capable hosts only.
     */
    public function testM1GetAtCallbackPathIsMethodNotAllowed(): void
    {
        $this->skipUnlessSupports('routing', 'M1');

        $response = $this->host->handle(new HostRequest('GET', $this->options->callbackPath, [], ''));

        self::assertSame(405, $response->status);
        self::assertSame([], $this->host->logRecords());
    }

    /**
     * M2: a custom callback path mounts, and the default path 404s once it is
     * no longer the mounted one. Routing-capable hosts only.
     */
    public function testM2CustomCallbackPathMountsAndOldPathIs404(): void
    {
        $this->skipUnlessSupports('routing', 'M2');

        $host = $this->createHost();
        $options = $this->defaultOptions(['callbackPath' => '/hooks/atoms']);
        $host->boot($options);

        try {
            $body = CallbackCases::methodsBody('GameRoom', 'g-1', 'add', [2, 3]);
            $request = CallbackCases::signedRequest($this->signer, $options, 'methods', $body);

            $response = $host->handle($request);
            self::assertSame(200, $response->status, 'new path should serve the kernel');

            $oldPathRequest = new HostRequest('POST', '/atoms/callback', $request->headers, $request->body);
            $oldPathResponse = $host->handle($oldPathRequest);
            self::assertSame(404, $oldPathResponse->status, 'old default path should no longer be mounted');
        } finally {
            $host->shutdown();
        }
    }

    /**
     * M3: a signed happy-path POST carries no CSRF token and no session
     * cookie, and gets back a 200 with no Set-Cookie header — proof that the
     * callback route is mounted outside whatever session/CSRF layer a
     * routing-capable host's framework provides. Laravel: the adapter
     * registers the route outside the "web" middleware group (no
     * EncryptCookies/StartSession/ValidateCsrfToken). Symfony: no session is
     * configured at all (Host/symfony-app has no `framework: session:`).
     * Meaningless for bare-kernel/plain-PHP, which have no CSRF/cookie layer
     * to exercise — routing-capable hosts only, same gate M1/M2 use.
     */
    public function testM3NoCsrfTokenNoSessionCookieRoundTripsCleanly(): void
    {
        $this->skipUnlessSupports('routing', 'M3');

        $body = CallbackCases::methodsBody('GameRoom', 'g-1', 'add', [2, 3]);
        $request = CallbackCases::signedRequest($this->signer, $this->options, 'methods', $body);

        $response = $this->host->handle($request);

        self::assertSame(200, $response->status);
        self::assertSame('{"result":5}', $response->body);

        foreach (array_keys($response->headers) as $name) {
            self::assertNotSame('set-cookie', strtolower($name), "M3: unexpected Set-Cookie header '{$name}'");
        }
    }

    /**
     * M4: a lowercase `x-atoms-kind` header produces the exact same envelope
     * as case 1 (methods happy add). Runs on ALL hosts, but proves two
     * different things depending on which one: LaravelHost, SymfonyHost and
     * PlainPhpHost each translate every header into a `$_SERVER`-shaped
     * `HTTP_*` key via their own `serverKey()`, uppercasing it first — the
     * same normalization a real PHP SAPI performs on `$_SERVER` before any
     * of those frameworks ever sees a header name. On those three hosts this
     * case is therefore a tautology proving HARNESS fidelity to that SAPI
     * behavior (a lowercase header was never going to reach the framework
     * looking different from an uppercase one), not the framework's own
     * case-insensitivity. Only BareKernelHost skips that translation — it
     * hands `$request->headers` straight to a PSR-7 `ServerRequestInterface`
     * via `withHeader()` — so it alone is what actually exercises PSR-7's own
     * case-insensitive header lookup, the mechanism `CallbackKernel::handle()`'s
     * `getHeaderLine()` calls depend on.
     */
    public function testM4LowercaseKindHeaderMatchesCaseOneEnvelope(): void
    {
        $body = CallbackCases::methodsBody('GameRoom', 'g-1', 'add', [2, 3]);
        $timestamp = $this->signer->now();
        $nonce = $this->signer->newNonce();
        $headers = $this->signer->sign($timestamp, $nonce, $body, 'methods');

        $headers['x-atoms-kind'] = $headers['X-Atoms-Kind'];
        unset($headers['X-Atoms-Kind']);

        $request = new HostRequest('POST', $this->options->callbackPath, $headers, $body);
        $response = $this->host->handle($request);

        self::assertSame(200, $response->status);
        self::assertSame('{"result":5}', $response->body);
    }

    /**
     * S1: AtomsClient::get()->method(...) lands on the host's httpFake() with
     * the URL/Authorization shape docs/conventions.md and AtomsClient commit
     * to. Client-capable hosts only.
     */
    public function testS1ClientCallLandsOnHttpFakeWithExpectedShape(): void
    {
        $this->skipUnlessSupports('client', 'S1');

        $this->host->httpFake()->queueJson(200, ['result' => 5]);

        /** @var AtomsClient $client */
        $client = $this->host->service(AtomsClient::class);
        $result = $client->get(GameRoom::class, 'g-1')->add(2, 3);

        self::assertSame(5, $result);

        $request = $this->host->httpFake()->lastRequest();
        self::assertSame($this->options->endpoint . '/invoke/GameRoom/g-1/add', (string) $request->getUri());
        self::assertSame('Bearer k', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
    }

    /**
     * S1 (continued): a null apiKey means no Authorization header at all —
     * AtomsConfig's "explicitly unauthenticated" posture, checked at the
     * transport a client-capable host actually sends through.
     */
    public function testS1ClientOmitsAuthorizationHeaderWhenApiKeyIsNull(): void
    {
        $this->skipUnlessSupports('client', 'S1');

        $host = $this->createHost();
        $options = $this->defaultOptions(['apiKey' => null]);
        $host->boot($options);

        try {
            $host->httpFake()->queueJson(200, ['result' => 5]);

            /** @var AtomsClient $client */
            $client = $host->service(AtomsClient::class);
            $client->get(GameRoom::class, 'g-1')->add(2, 3);

            $request = $host->httpFake()->lastRequest();
            self::assertFalse($request->hasHeader('Authorization'));
        } finally {
            $host->shutdown();
        }
    }

    /**
     * S2: apiKey='' is a configuration error, not a posture — AtomsConfig
     * throws at construction, and that throw must surface through the host's
     * own wiring path (AtomsBootstrap::create() for plain-PHP). Client-
     * capable hosts only.
     */
    public function testS2EmptyApiKeyThrowsAtConstruction(): void
    {
        $this->skipUnlessSupports('client', 'S2');

        $host = $this->createHost();
        $options = $this->defaultOptions(['apiKey' => '']);

        $this->expectException(\InvalidArgumentException::class);

        $host->boot($options);
    }

    /**
     * S4: with no queue wired (queueAvailable=false), a job callback fails
     * loudly — 500 ATOMS-E103 — instead of silently dropping the job.
     * Queue-capable hosts only.
     */
    public function testS4QueueUnavailableJobCallbackReturns500WithE103(): void
    {
        $this->skipUnlessSupports('queue', 'S4');

        $host = $this->createHost();
        $options = $this->defaultOptions(['queueAvailable' => false]);
        $host->boot($options);

        try {
            $body = CallbackCases::jobBody(RecordScoreJob::class, ['playerId' => 'p1', 'score' => 100]);
            $request = CallbackCases::signedRequest($this->signer, $options, 'job', $body);

            $response = $host->handle($request);

            self::assertSame(500, $response->status);
            $decoded = json_decode($response->body, true);
            self::assertIsArray($decoded);
            self::assertSame('ATOMS-E103', $decoded['error']['code'] ?? null);
        } finally {
            $host->shutdown();
        }
    }

    /**
     * S5: the boom() case's log record carries the context keys the kernel
     * actually emits (type/method/exception) — case 12 in the data provider
     * only checks that SOME error record exists; this checks its shape.
     */
    public function testS5BoomLogRecordCarriesExpectedContextKeys(): void
    {
        $this->skipUnlessSupports('logging', 'S5');

        $body = CallbackCases::methodsBody('GameRoom', 'g-1', 'boom', []);
        $request = CallbackCases::signedRequest($this->signer, $this->options, 'methods', $body);

        $this->host->handle($request);

        $errorRecords = $this->errorLogRecords();
        self::assertNotEmpty($errorRecords);

        $context = $errorRecords[0]['context'];
        self::assertArrayHasKey('type', $context);
        self::assertArrayHasKey('method', $context);
        self::assertArrayHasKey('exception', $context);
        self::assertSame('GameRoom', $context['type']);
        self::assertSame('boom', $context['method']);
        self::assertSame(\RuntimeException::class, $context['exception']);
    }

    /**
     * S6: {@see \Atoms\Tests\Integration\Adapters\Fixtures\RankRoom\MethodsWithDependency}
     * takes a {@see \Atoms\Tests\Integration\Adapters\Fixtures\Scoreboard} in
     * its constructor — something a bare `new $class()` cannot supply — so a
     * 200 with the real formatted result is only possible if the host's own
     * container built it: Laravel's `$app` (bound via `$app->instance()`,
     * see LaravelHost::boot()); Symfony's `ServiceLocator`, built by
     * `AtomsBundle::registerCallbackStack()` from the fixture's
     * `methods_classes` + `Scoreboard` autowired in `services.php`; and, for
     * the two hosts with no framework container of their own, a stub
     * {@see \Atoms\Tests\Integration\Adapters\Support\ArrayContainer} threaded
     * through the exact same `container:` parameter a real framework-free
     * host could use (see BareKernelHost/PlainPhpHost). This is exactly the
     * "Methods instantiation" port `docs/adapters.md` documents.
     * Container-capable hosts only.
     *
     * The port's other half — a Methods class NOT registered in the
     * container instantiates via `new $class()` — is proven implicitly by
     * every OTHER case in this suite:
     * {@see \Atoms\Tests\Integration\Adapters\Fixtures\GameRoom\Methods} is
     * never registered in any host's container, and every other test still
     * passes; nothing here duplicates that proof.
     */
    public function testS6MethodsClassWithConstructorDependencyResolvesFromHostContainer(): void
    {
        $this->skipUnlessSupports('container', 'S6');

        $host = $this->createHost();
        $scoreboard = new Scoreboard();
        $methods = new RankRoomMethods($scoreboard);

        $options = $this->defaultOptions([
            'methodsClasses' => [GameRoomMethods::class, RankRoomMethods::class],
            'containerBindings' => [
                Scoreboard::class => $scoreboard,
                RankRoomMethods::class => $methods,
            ],
        ]);

        $host->boot($options);

        try {
            $body = CallbackCases::methodsBody('RankRoom', 'r-1', 'rank', [3]);
            $request = CallbackCases::signedRequest($this->signer, $options, 'methods', $body);

            $response = $host->handle($request);

            self::assertSame(200, $response->status);
            self::assertSame('{"result":"Score: 3"}', $response->body);
        } finally {
            $host->shutdown();
        }
    }

    /**
     * S7: a NonceStore supplied via HostOptions is the one the host's kernel
     * actually consults, not a default it silently fell back to.
     */
    public function testS7SuppliedNonceStoreObservesNonces(): void
    {
        $host = $this->createHost();
        $nonceStore = new RecordingNonceStore();
        $options = $this->defaultOptions(['nonceStore' => $nonceStore]);

        $host->boot($options);

        try {
            $body = CallbackCases::methodsBody('GameRoom', 'g-1', 'add', [2, 3]);
            $request = CallbackCases::signedRequest($this->signer, $options, 'methods', $body);

            $response = $host->handle($request);

            self::assertSame(200, $response->status);
            self::assertNotEmpty($nonceStore->seen);
            self::assertSame($request->headers['X-Atoms-Nonce'], $nonceStore->seen[0]);
        } finally {
            $host->shutdown();
        }
    }

    private function assertJsonCode(CallbackCase $case, string $body): void
    {
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, "case '{$case->key}': body must be JSON");
        self::assertIsArray($decoded['error'] ?? null, "case '{$case->key}': body must carry an error envelope");
        self::assertSame($case->expectedErrorCode, $decoded['error']['code'] ?? null, "case '{$case->key}': error code");

        foreach ($case->expectedMessageContains as $needle) {
            self::assertStringContainsString(
                $needle,
                (string) ($decoded['error']['message'] ?? ''),
                "case '{$case->key}': error message",
            );
        }
    }

    private function skipUnlessSupports(string $capability, string $caseLabel): void
    {
        if (!$this->host->supports($capability)) {
            $this->failOrSkipMissingCapability($capability, $caseLabel);
        }
    }

    /**
     * The decision behind every capability gate in this class: a capability
     * the host doesn't support (per {@see AdapterHost::supports()}) is only
     * ever safe to skip if {@see self::expectedMissingCapabilities()}
     * declares it. Declared → skip, exactly as before. Undeclared → fail,
     * because there is no way from here to tell "this host never had this
     * capability and the declaration is stale" apart from "this host just
     * silently lost a capability it used to have" — a skip would hide either
     * one; failing forces a human to look and fix the right side (the host,
     * or the declaration).
     */
    private function failOrSkipMissingCapability(string $capability, string $caseLabel): void
    {
        if (in_array($capability, $this->expectedMissingCapabilities(), true)) {
            self::markTestSkipped(sprintf(
                "Host '%s' does not support capability '%s' (%s).",
                $this->host->name(),
                $capability,
                $caseLabel,
            ));
        }

        self::fail(sprintf(
            "Host '%s' does not support capability '%s' (%s), but '%s' is not declared in %s::expectedMissingCapabilities(). "
            . 'This is either a real regression (the host used to support this capability — check %s::supports()) '
            . 'or a deliberate new gap that %s::expectedMissingCapabilities() must be updated to declare.',
            $this->host->name(),
            $capability,
            $caseLabel,
            $capability,
            static::class,
            $this->host::class,
            static::class,
        ));
    }

    /**
     * @return list<array{level: string, message: string, context: array<array-key, mixed>}>
     */
    private function errorLogRecords(): array
    {
        return array_values(array_filter(
            $this->host->logRecords(),
            static fn (array $record): bool => $record['level'] === 'error',
        ));
    }
}
