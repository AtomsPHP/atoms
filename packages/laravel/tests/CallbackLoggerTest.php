<?php

declare(strict_types=1);

namespace Atoms\Laravel\Tests;

use Atoms\Laravel\Tests\Fixtures\GameRoom;
use Atoms\Laravel\Tests\Support\RecordingLogger;
use Psr\Log\LoggerInterface;

/**
 * Covers T7's other supply-contract gap: AtomsServiceProvider must pass the
 * app's bound Psr\Log\LoggerInterface into CallbackKernel (arg 10), so a
 * Methods invocation that throws gets logged through the host app's own
 * logging stack rather than silently swallowed. Uses the base TestCase's
 * signing helper.
 */
final class CallbackLoggerTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // Bind BEFORE anything resolves CallbackKernel (it's a lazily-built
        // singleton, first constructed when the route dispatches the request
        // below) so the provider's closure sees the binding and wires it in.
        $this->logger = new RecordingLogger();
        $this->app->instance(LoggerInterface::class, $this->logger);
    }

    public function testMethodsInvocationThrowingLogsAnErrorRecord(): void
    {
        [$server, $body] = $this->signedCallback('methods', [
            'atom' => ['type' => GameRoom::class, 'id' => 'g-1'],
            'method' => 'explode',
            'args' => [],
        ]);

        $response = $this->call('POST', '/atoms/callback', [], [], [], $server, $body);

        $response->assertStatus(500);

        $errorRecords = array_values(array_filter(
            $this->logger->records,
            static fn (array $record): bool => $record['level'] === 'error',
        ));

        self::assertNotEmpty($errorRecords);
        self::assertSame('Callback Methods invocation threw', $errorRecords[0]['message']);
    }
}
