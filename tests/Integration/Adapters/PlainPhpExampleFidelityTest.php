<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters;

use Atoms\Client\Callback\MethodsResolver;
use Atoms\Examples\PlainPhp\ArrayQueueBridge;
use Atoms\Examples\PlainPhp\Atoms\GameRoom\Methods as ExampleMethods;
use Atoms\Examples\PlainPhp\AtomsBootstrap;
use Atoms\Examples\PlainPhp\PlainPhpApp;
use Atoms\Tests\Integration\Adapters\Fixtures\RecordScoreJob;
use Atoms\Tests\Integration\Adapters\Support\CallbackSigner;
use Atoms\Tests\Integration\Adapters\Support\FakePsr18Client;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * §4: `examples/AGENTS.md` claims every class under `plain-php/src/` is
 * exercised by this suite, but `Host/PlainPhpHost.php` — the host every
 * OTHER test in this directory drives — substitutes suite-owned doubles for
 * two of those five classes: {@see \Atoms\Tests\Integration\Adapters\Support\RecordingQueueBridge}
 * stands in for the example's OWN {@see ArrayQueueBridge}, and
 * {@see \Atoms\Tests\Integration\Adapters\Fixtures\GameRoom\Methods} (a
 * suite fixture) stands in for the example's OWN
 * `Atoms\Examples\PlainPhp\Atoms\GameRoom\Methods`. That substitution is
 * deliberate — it is what lets `PlainPhpAdapterConformanceTest` share ONE
 * case table with every other host — but it means `ArrayQueueBridge` and the
 * example's `GameRoom` Methods class can each be broken (a renamed method, a
 * dropped `enqueue()` call) with the entire suite staying green. This class
 * closes that gap: it boots `AtomsBootstrap::create()` with the example's
 * REAL `ArrayQueueBridge` and REAL `GameRoom\Methods` — nothing suite-owned
 * standing in for either — and drives both through
 * {@see PlainPhpApp::handleGlobals()}, the exact front-controller path
 * `public/atoms-callback.php` uses.
 */
final class PlainPhpExampleFidelityTest extends TestCase
{
    private const CALLBACK_PATH = '/atoms/callback';

    private CallbackSigner $signer;

    private ArrayQueueBridge $queue;

    private PlainPhpApp $app;

    protected function setUp(): void
    {
        $this->signer = new CallbackSigner();
        $this->queue = new ArrayQueueBridge();

        $resolver = new MethodsResolver();
        $resolver->registerMethodsClass(ExampleMethods::class);

        $factory = new HttpFactory();

        $this->app = AtomsBootstrap::create(
            endpoint: 'http://worker.test',
            apiKey: 'k',
            platformPublicKey: $this->signer->publicKeyBase64(),
            callbackPath: self::CALLBACK_PATH,
            http: new FakePsr18Client(),
            requestFactory: $factory,
            serverRequestFactory: $factory,
            responseFactory: $factory,
            streamFactory: $factory,
            // The example's OWN queue bridge — not RecordingQueueBridge.
            queueBridge: $this->queue,
            // The example's OWN Methods class — not Fixtures\GameRoom\Methods.
            resolver: $resolver,
        );
    }

    /**
     * Drives a signed methods callback against the example's REAL
     * `GameRoom\Methods::displayName()` and asserts its real return value —
     * `sprintf('Player %s', $playerId)`. Rename or break that method and
     * this fails; `PlainPhpAdapterConformanceTest` would not notice, because
     * it never resolves this class at all.
     */
    public function testMethodsCallbackInvokesTheExamplesRealMethodsClass(): void
    {
        $body = CallbackCases::methodsBody('GameRoom', 'g-1', 'displayName', ['p-1']);

        $response = $this->send('methods', $body);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"result":"Player p-1"}', (string) $response->getBody());
    }

    /**
     * Drives a signed job callback — reusing the suite's
     * {@see RecordScoreJob} fixture, since any concrete {@see \Atoms\AtomJob}
     * proves the point — and asserts the example's REAL `ArrayQueueBridge`
     * captured the reconstructed job. Rename or break
     * `ArrayQueueBridge::enqueue()` and this fails;
     * `PlainPhpAdapterConformanceTest` would not notice, because it wires
     * `RecordingQueueBridge` instead.
     */
    public function testJobCallbackIsCapturedByTheExamplesRealArrayQueueBridge(): void
    {
        $body = CallbackCases::jobBody(RecordScoreJob::class, ['playerId' => 'p1', 'score' => 100]);

        $response = $this->send('job', $body);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"queued":true}', (string) $response->getBody());

        $jobs = $this->queue->jobs();
        self::assertCount(1, $jobs);
        self::assertInstanceOf(RecordScoreJob::class, $jobs[0]);
        self::assertSame('p1', $jobs[0]->playerId);
        self::assertSame(100, $jobs[0]->score);
    }

    private function send(string $kind, string $body): ResponseInterface
    {
        $timestamp = $this->signer->now();
        $nonce = $this->signer->newNonce();
        $headers = $this->signer->sign($timestamp, $nonce, $body, $kind);

        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => self::CALLBACK_PATH,
        ];

        foreach ($headers as $name => $value) {
            $server[$this->serverKey($name)] = $value;
        }

        return $this->app->handleGlobals($server, $body);
    }

    /**
     * Mirror the real `$_SERVER` shape for one header — same mapping
     * {@see \Atoms\Tests\Integration\Adapters\Host\PlainPhpHost::serverKey()}
     * uses, kept in step for the same reason.
     */
    private function serverKey(string $headerName): string
    {
        $normalized = strtoupper(str_replace('-', '_', $headerName));

        return match ($normalized) {
            'CONTENT_TYPE', 'CONTENT_LENGTH' => $normalized,
            default => 'HTTP_' . $normalized,
        };
    }
}
