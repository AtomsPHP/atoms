<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Callback;

use Atoms\AtomJob;
use Atoms\AtomMethods;
use Atoms\Client\Callback\CallbackKernel;
use Atoms\Client\Callback\HmacVerifier;
use Atoms\Client\Callback\LazyNonceStore;
use Atoms\Client\Callback\MethodsResolver;
use Atoms\Client\Callback\NonceStore;
use Atoms\Client\Tests\Support\CallbackKernelClock;
use Atoms\Client\Tests\Support\RecordingQueueBridge;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class CallbackKernelExpiryTest extends TestCase
{
    public static function delayedCallbacks(): iterable
    {
        yield 'Methods, backend initialization' => ['methods', 'initialization'];
        yield 'Methods, atomic claim' => ['methods', 'claim'];
        yield 'job, backend initialization' => ['job', 'initialization'];
        yield 'job, atomic claim' => ['job', 'claim'];
    }

    #[DataProvider('delayedCallbacks')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReplayExpiringDuringStorageDoesNotRepeatCustomerSideEffects(string $kind, string $stage): void
    {
        // A namespace-local clock avoids sleeps and is confined to this process.
        require __DIR__ . '/../Support/CallbackKernelClock.php';
        CallbackKernelClock::$now = 999;
        ExpiryMethods::$constructed = 0;
        ExpiryMethods::$invoked = 0;
        ExpiryJob::$constructed = 0;

        $store = new class ($stage) implements NonceStore {
            public int $claims = 0;
            public bool $delay = false;
            private ?int $expiresAt = null;

            public function __construct(private readonly string $stage) {}

            public function seen(string $nonce): bool
            {
                ++$this->claims;
                if ($this->delay && $this->stage === 'claim') {
                    CallbackKernelClock::$now = 1003;
                }
                if ($this->expiresAt !== null && $this->expiresAt > CallbackKernelClock::$now) {
                    return true;
                }
                // Three seconds covers a one-second window in both directions.
                $this->expiresAt = CallbackKernelClock::$now + 3;

                return false;
            }
        };
        $factory = new HttpFactory();
        $bridge = new RecordingQueueBridge();
        $key = str_repeat('k', 32);
        $initializations = 0;
        $newKernel = static function () use ($store, $stage, $factory, $bridge, $key, &$initializations): CallbackKernel {
            $lazy = new LazyNonceStore(static function () use ($store, $stage, &$initializations): NonceStore {
                ++$initializations;
                if ($store->delay && $stage === 'initialization') {
                    CallbackKernelClock::$now = 1003;
                }

                return $store;
            });

            return new CallbackKernel(
                new HmacVerifier([$key]), $lazy,
                (new MethodsResolver())->map(['Room' => ExpiryMethods::class]),
                $bridge, $factory, $factory, timestampWindow: 1,
            );
        };
        $payload = $kind === 'methods'
            ? ['atom' => ['type' => 'Room', 'id' => 'r-1'], 'method' => 'run', 'args' => []]
            : ['job' => ExpiryJob::class, 'args' => ['id' => 'r-1']];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $nonce = str_repeat('a', 32);
        $request = $factory->createServerRequest('POST', 'https://app.test/_atoms/callback')
            ->withHeader('X-Atoms-Kind', $kind)
            ->withHeader('X-Atoms-Timestamp', '1000')
            ->withHeader('X-Atoms-Nonce', $nonce)
            ->withHeader('X-Atoms-Signature', base64_encode(hash_hmac('sha256', "v1\n1000\n{$nonce}\n{$body}", $key, true)))
            ->withBody($factory->createStream($body));

        self::assertSame(200, $newKernel()->handle($request)->getStatusCode());
        self::assertSame($kind === 'methods' ? 1 : 0, ExpiryMethods::$constructed);
        self::assertSame($kind === 'methods' ? 1 : 0, ExpiryMethods::$invoked);
        self::assertSame($kind === 'job' ? 1 : 0, ExpiryJob::$constructed);
        self::assertCount($kind === 'job' ? 1 : 0, $bridge->jobs);

        // Replay enters at the last fresh second. Storage resumes after both
        // its original nonce claim and the signed timestamp have expired.
        CallbackKernelClock::$now = 1001;
        $store->delay = true;
        $response = $newKernel()->handle($request);

        self::assertSame(2, $initializations);
        self::assertSame(2, $store->claims);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('ATOMS-E065', json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['error']['code']);
        self::assertSame($kind === 'methods' ? 1 : 0, ExpiryMethods::$constructed);
        self::assertSame($kind === 'methods' ? 1 : 0, ExpiryMethods::$invoked);
        self::assertSame($kind === 'job' ? 1 : 0, ExpiryJob::$constructed);
        self::assertCount($kind === 'job' ? 1 : 0, $bridge->jobs);
    }
}

final class ExpiryMethods extends AtomMethods
{
    public static int $constructed = 0;
    public static int $invoked = 0;

    public function __construct()
    {
        ++self::$constructed;
    }

    public function run(): void
    {
        ++self::$invoked;
    }
}

final class ExpiryJob extends AtomJob
{
    public static int $constructed = 0;

    public function __construct(public string $id)
    {
        ++self::$constructed;
    }
}
