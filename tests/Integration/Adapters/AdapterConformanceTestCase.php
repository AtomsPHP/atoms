<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters;

use Atoms\Client\AtomsClient;
use Atoms\Tests\Integration\Adapters\Fixtures\GameRoom;
use Atoms\Tests\Integration\Adapters\Fixtures\GameRoom\Methods as GameRoomMethods;
use Atoms\Tests\Integration\Adapters\Fixtures\RecordScoreJob;
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
 */
abstract class AdapterConformanceTestCase extends TestCase
{
    protected AdapterHost $host;

    protected CallbackSigner $signer;

    protected HostOptions $options;

    abstract protected function createHost(): AdapterHost;

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
     * @param array{endpoint?: string, apiKey?: ?string, publicKey?: string, callbackPath?: string, methodsClasses?: list<class-string>, nonceStore?: ?\Atoms\Client\Callback\NonceStore, queueAvailable?: bool} $overrides
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
                self::markTestSkipped(sprintf(
                    "Host '%s' does not support capability '%s' (case '%s').",
                    $this->host->name(),
                    $capability,
                    $case->key,
                ));
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
     * M3 (CSRF/cookie handling) is only meaningful for framework hosts, which
     * this task does not add. Left as a marked seam for T9b — do not fake it
     * here against bare-kernel/plain-PHP, which have no CSRF/cookie layer to
     * exercise.
     */

    /**
     * M4: a lowercase `x-atoms-kind` header produces the exact same envelope
     * as case 1 (methods happy add). Runs on ALL hosts — this is what proves
     * a host's header lookup is not accidentally case-sensitive.
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
            self::markTestSkipped(sprintf(
                "Host '%s' does not support capability '%s' (%s).",
                $this->host->name(),
                $capability,
                $caseLabel,
            ));
        }
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
