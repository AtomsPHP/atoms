<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Callback;

use Atoms\Client\Callback\NonceStoreConfigurationException;
use Atoms\Client\Callback\NonceStoreOptions;
use Atoms\Client\Callback\NonceStoreUnavailableException;
use Atoms\Client\Callback\RedisNonceStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\Command\CommandInterface;
use Predis\Connection\ConnectionInterface;

final class RedisNonceStoreTest extends TestCase
{
    public function testIndependentPredisClientsUseTheSameAtomicClaimAndRetention(): void
    {
        $options = NonceStoreOptions::fromArray(['namespace' => 'app:production'], 300);
        $records = [];
        $commands = [];
        $connect = function () use (&$records, &$commands): Client {
            $connection = $this->createMock(ConnectionInterface::class);
            $connection->method('getParameters')->willReturn(new \Predis\Connection\Parameters());
            $connection->method('executeCommand')->willReturnCallback(
                static function (CommandInterface $command) use (&$records, &$commands): int {
                    self::assertSame('EVAL', $command->getId());
                    $arguments = $command->getArguments();
                    $commands[] = $arguments;
                    self::assertStringContainsString("redis.call('SET', KEYS[1], '1', 'NX', 'EX', ARGV[1])", $arguments[0]);
                    self::assertSame(1, $arguments[1]);
                    self::assertSame('661', $arguments[3]);
                    $key = $arguments[2];
                    if (isset($records[$key])) {
                        return 0;
                    }
                    $records[$key] = true;
                    return 1;
                },
            );
            return new Client($connection);
        };

        self::assertFalse(RedisNonceStore::fromClient($connect(), $options)->seen('nonce'));
        self::assertTrue(RedisNonceStore::fromClient($connect(), $options)->seen('nonce'));
        self::assertCount(1, $records);
        self::assertSame($commands[0], $commands[1]);
        self::assertSame('atoms:callback-nonce:' . $options->key('nonce'), $commands[0][2]);
    }

    public static function invalidReplies(): iterable
    {
        yield 'lost response' => [false];
        yield 'null' => [null];
        yield 'queued status' => ['QUEUED'];
        yield 'integer string' => ['1'];
        yield 'boolean success' => [true];
        yield 'unexpected integer' => [2];
        yield 'pipeline object' => [new \stdClass()];
    }

    #[DataProvider('invalidReplies')]
    public function testAmbiguousRepliesFailClosed(mixed $reply): void
    {
        $store = new RedisNonceStore(static fn (): mixed => $reply, $this->options());
        $this->expectException(NonceStoreUnavailableException::class);
        $store->seen('nonce');
    }

    public function testBackendExceptionsDoNotExposeConnectionDetails(): void
    {
        $cause = new \RuntimeException('redis://user:secret@host');
        $store = new RedisNonceStore(static fn () => throw $cause, $this->options());
        try {
            $store->seen('nonce');
            self::fail('Expected a backend failure.');
        } catch (NonceStoreUnavailableException $error) {
            self::assertSame($cause, $error->getPrevious());
            self::assertStringNotContainsString('secret', $error->getMessage());
            self::assertStringContainsString('ATOMS-E112', $error->getMessage());
        }
    }

    public function testArbitraryClientObjectsAreNotAssumedToBeRedisConnections(): void
    {
        $this->expectException(NonceStoreConfigurationException::class);
        RedisNonceStore::fromClient(new \stdClass(), $this->options());
    }

    public static function nativeClients(): iterable
    {
        yield 'PhpRedis' => ['Redis'];
        yield 'PhpRedis cluster' => ['RedisCluster'];
    }

    #[DataProvider('nativeClients')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNativeClientUsesPhpRedisArgumentOrder(string $class): void
    {
        if (!class_exists($class)) {
            class_alias(NativeRedisDouble::class, $class);
        }
        $client = $this->getMockBuilder($class)->disableOriginalConstructor()->onlyMethods(['getMode', 'eval'])->getMock();
        $client->method('getMode')->willReturn(0);
        $client->expects(self::once())->method('eval')->willReturnCallback(
            static function (string $script, array $arguments, int $keyCount): int {
                self::assertSame(1, $keyCount);
                self::assertCount(2, $arguments);
                self::assertSame('661', $arguments[1]);
                self::assertStringContainsString("'NX', 'EX'", $script);
                return 1;
            },
        );
        $store = RedisNonceStore::fromClient($client, $this->options());
        self::assertFalse($store->seen('nonce'));
    }

    public static function queuedModes(): iterable
    {
        yield 'transaction' => [1];
        yield 'pipeline' => [2];
    }

    #[DataProvider('queuedModes')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNativeQueuedModeCannotQueueAClaim(int $mode): void
    {
        if (!class_exists('Redis')) {
            class_alias(NativeRedisDouble::class, 'Redis');
        }
        $client = $this->getMockBuilder('Redis')->disableOriginalConstructor()->onlyMethods(['getMode', 'eval'])->getMock();
        $client->method('getMode')->willReturn($mode);
        $client->expects(self::never())->method('eval');
        $store = RedisNonceStore::fromClient($client, $this->options());
        $this->expectException(NonceStoreConfigurationException::class);
        $store->seen('nonce');
    }

    public function testPredisPipelineAndTransactionContextsAreRejected(): void
    {
        $client = new Client();
        foreach ([$client->pipeline(), $client->transaction()] as $context) {
            try {
                RedisNonceStore::fromClient($context, $this->options());
                self::fail('A queued client context must not accept nonce claims.');
            } catch (NonceStoreConfigurationException $error) {
                self::assertStringContainsString('ATOMS-E111', $error->getMessage());
            }
        }
    }

    private function options(): NonceStoreOptions
    {
        return NonceStoreOptions::fromArray(['namespace' => 'app:production'], 300);
    }
}

/** Native call shape used only in isolated tests when ext-redis is absent. */
class NativeRedisDouble
{
    public function getMode(): int
    {
        return 0;
    }

    public function eval(string $script, array $arguments, int $keyCount): mixed
    {
        throw new \LogicException('The test must replace the command executor.');
    }
}
