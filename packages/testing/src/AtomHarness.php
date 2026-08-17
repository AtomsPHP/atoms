<?php

declare(strict_types=1);

namespace Atoms\Testing;

use Atoms\Atom;
use Atoms\Database;
use Atoms\Migrations\MigrationSet;
use Atoms\Migrations\Migrator;
use Atoms\Runtime\LifecycleInvoker;
use Atoms\Serialization\Serializer;
use Atoms\Sqlite\SqliteDatabase;
use Atoms\Testing\Internal\Boundary;
use Atoms\Testing\Internal\HarnessAtomContext;
use Atoms\Testing\Internal\TemporaryDirectory;
use PHPUnit\Framework\Assert;

/**
 * In-process test harness for an Atom: a real temp-file SQLite database with
 * migrations applied, a fake `app()` proxy that executes the real Methods
 * class in-process, and recorders for `dispatch()`/`broadcast()` with PHPUnit
 * assertion helpers. No network, no Docker — see
 * docs/integration-plan.md §6.3.
 *
 * @template TAtom of Atom
 */
final class AtomHarness
{
    /** @var class-string<TAtom> */
    private readonly string $atomClass;

    private object|string|null $methods = null;

    private ?string $migrationsDir = null;

    /** @var array<string, mixed> */
    private array $config = [];

    private bool $booted = false;

    private bool $shutDown = false;

    private ?TemporaryDirectory $tempDir = null;

    private ?Database $database = null;

    /** @var TAtom|null */
    private ?Atom $atom = null;

    private readonly Serializer $serializer;

    /** @var list<array{job: string, args: array<string, mixed>}> the wire shape */
    private array $dispatched = [];

    /** @var list<array{channel: string, payload: array<string, mixed>}> */
    private array $broadcasts = [];

    private readonly FakeTimers $timers;

    /**
     * @param class-string<TAtom> $atomClass
     */
    private function __construct(string $atomClass, private readonly string $id)
    {
        $this->atomClass = $atomClass;
        $this->serializer = new Serializer();
        $this->timers = new FakeTimers();
    }

    /**
     * @template T of Atom
     * @param class-string<T> $atomClass
     * @return self<T>
     */
    public static function for(string $atomClass, string $id): self
    {
        return new self($atomClass, $id);
    }

    /**
     * Override Methods-class resolution. Accepts an instance (used as-is) or a
     * class-string (instantiated with no constructor arguments). Must be
     * called before the harness is booted.
     *
     * @return self<TAtom>
     */
    public function withMethods(object|string $classOrInstance): self
    {
        $this->assertNotBooted();
        $this->methods = $classOrInstance;

        return $this;
    }

    /**
     * Override migrations-directory resolution. Must be called before the
     * harness is booted.
     *
     * @return self<TAtom>
     */
    public function withMigrations(string $dir): self
    {
        $this->assertNotBooted();
        $this->migrationsDir = $dir;

        return $this;
    }

    /**
     * Provide the values `config()` resolves; missing keys resolve to null.
     * Must be called before the harness is booted.
     *
     * @param array<string, mixed> $kv
     * @return self<TAtom>
     */
    public function withConfig(array $kv): self
    {
        $this->assertNotBooted();
        $this->config = $kv;

        return $this;
    }

    /**
     * Idempotent: opens a unique temp SQLite database, applies migrations,
     * constructs the Atom against the harness's {@see HarnessAtomContext}, and
     * runs the activation lifecycle hook. Implicitly called by every other
     * harness method that needs a live Atom.
     *
     * @return self<TAtom>
     */
    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }

        $this->booted = true;

        $this->tempDir = TemporaryDirectory::create();
        $this->database = SqliteDatabase::open($this->tempDir->path() . '/atom.sqlite');

        $migrationsDir = $this->migrationsDir ?? $this->defaultMigrationsDir();
        $set = MigrationSet::fromDirectory($migrationsDir);
        (new Migrator())->apply($this->database, $set);

        $appProxy = new FakeAppProxy($this->resolveMethodsInstance(), $this->serializer);

        $context = new HarnessAtomContext(
            $this->database,
            $appProxy,
            $this->config,
            function (string $job, array $args): void {
                $this->dispatched[] = ['job' => $job, 'args' => $args];
            },
            function (string $channel, array $payload): void {
                $this->broadcasts[] = ['channel' => $channel, 'payload' => $payload];
            },
            $this->timers,
        );

        $atomClass = $this->atomClass;
        /** @var TAtom $atom */
        $atom = new $atomClass($this->id, $context);
        $this->atom = $atom;

        LifecycleInvoker::activate($this->atom);

        return $this;
    }

    /**
     * Invoke a public method on the Atom, round-tripping arguments and the
     * return value through the Atoms serialization boundary exactly as the
     * production runtime would — a boundary violation throws
     * {@see \Atoms\Serialization\SerializationException} here, not at deploy
     * time.
     *
     * @param list<mixed> $args
     */
    public function invoke(string $method, array $args = []): mixed
    {
        $this->boot();

        /** @var TAtom $atom */
        $atom = $this->atom;
        $reflection = new \ReflectionMethod($atom, $method);
        $coerced = Boundary::roundTripArgs($args, $reflection, $this->serializer);
        $result = $reflection->invoke($atom, ...$coerced);

        return Boundary::roundTripReturn($result, $reflection->getReturnType(), $this->serializer);
    }

    public function db(): Database
    {
        $this->boot();

        /** @var Database $database */
        $database = $this->database;

        return $database;
    }

    /**
     * The {@see FakeTimers} backing this Atom's `$this->timers()`, for
     * asserting what was scheduled/cancelled.
     */
    public function timers(): FakeTimers
    {
        $this->boot();

        return $this->timers;
    }

    /**
     * @return TAtom
     */
    public function atom(): Atom
    {
        $this->boot();

        /** @var TAtom $atom */
        $atom = $this->atom;

        return $atom;
    }

    /**
     * Simulate a client connecting: builds (or accepts) a {@see FakeConnection}
     * and dispatches it to `onConnect()`.
     *
     * @param array<string, string> $params
     */
    public function connect(array $params = [], ?FakeConnection $conn = null): FakeConnection
    {
        $this->boot();
        $conn ??= new FakeConnection();

        $this->atom()->onConnect($conn, $params);

        return $conn;
    }

    /**
     * Simulate an inbound frame on an existing connection, dispatched to
     * `onMessage()`.
     */
    public function sendMessage(FakeConnection $conn, string $payload, bool $binary = false): void
    {
        $this->boot();
        $this->atom()->onMessage($conn, new FakeMessage($payload, $binary));
    }

    /**
     * Simulate the connection closing, dispatched to `onDisconnect()`.
     */
    public function disconnect(FakeConnection $conn): void
    {
        $this->boot();
        $this->atom()->onDisconnect($conn);
    }

    /**
     * Every job dispatched so far, rebuilt the way the callback kernel would —
     * arguments round-tripped through the serializer, then a fresh instance. A
     * recorded call alone would prove neither that the arguments are wire-safe
     * nor that they satisfy the constructor.
     *
     * @return list<object>
     */
    public function dispatched(): array
    {
        return array_map(
            fn (array $record): object => $this->reconstruct($record['job'], $record['args']),
            $this->dispatched,
        );
    }

    /**
     * @return list<array{channel: string, payload: array<string, mixed>}>
     */
    public function broadcasts(): array
    {
        return $this->broadcasts;
    }

    /**
     * @param (callable(object): bool)|null $filter
     */
    public function assertDispatched(string $jobClass, ?callable $filter = null): void
    {
        $found = false;

        foreach ($this->dispatched() as $job) {
            if ($job instanceof $jobClass && ($filter === null || $filter($job))) {
                $found = true;

                break;
            }
        }

        Assert::assertTrue($found, sprintf(
            'Expected %s to have been dispatched%s.',
            $jobClass,
            $filter !== null ? ' matching the given filter' : '',
        ));
    }

    public function assertNothingDispatched(): void
    {
        Assert::assertSame([], $this->dispatched, 'Expected nothing to have been dispatched.');
    }

    /**
     * @param (callable(array<string, mixed>): bool)|null $filter
     */
    public function assertBroadcast(string $channel, ?callable $filter = null): void
    {
        $found = false;

        foreach ($this->broadcasts as $broadcast) {
            if ($broadcast['channel'] === $channel && ($filter === null || $filter($broadcast['payload']))) {
                $found = true;

                break;
            }
        }

        Assert::assertTrue($found, sprintf(
            "Expected a broadcast on channel '%s'%s.",
            $channel,
            $filter !== null ? ' matching the given filter' : '',
        ));
    }

    /**
     * Runs the deactivation lifecycle hook and removes the harness's temp
     * directory. Idempotent; also called from `__destruct()`.
     */
    public function shutdown(): void
    {
        if ($this->shutDown) {
            return;
        }

        $this->shutDown = true;

        if ($this->atom !== null) {
            LifecycleInvoker::deactivate($this->atom);
        }

        $this->tempDir?->delete();
    }

    public function __destruct()
    {
        $this->shutdown();
    }

    private function assertNotBooted(): void
    {
        if ($this->booted) {
            throw new \LogicException('AtomHarness is already booted; call withMethods()/withMigrations()/withConfig() before first use (boot(), invoke(), db(), atom(), connect()...).');
        }
    }

    private function defaultMigrationsDir(): string
    {
        $reflection = new \ReflectionClass($this->atomClass);
        $file = $reflection->getFileName();

        if ($file === false) {
            return '';
        }

        return \dirname($file) . '/' . $reflection->getShortName() . '/migrations';
    }

    private function resolveMethodsInstance(): ?object
    {
        if (\is_object($this->methods)) {
            return $this->methods;
        }

        if (\is_string($this->methods)) {
            return new ($this->methods)();
        }

        $default = $this->atomClass . '\\Methods';

        return class_exists($default) ? new $default() : null;
    }

    /**
     * @param array<string, mixed> $args keyed by constructor parameter name
     */
    private function reconstruct(string $jobClass, array $args): object
    {
        if (!class_exists($jobClass)) {
            throw new \InvalidArgumentException(sprintf(
                'Atoms: %s was dispatched but the class does not exist. In your app it must be '
                . 'autoloadable — only the platform runtime is allowed not to know it.',
                $jobClass,
            ));
        }

        // The arguments cross the boundary as JSON before anything binds them,
        // so round-trip them first — a value that is not wire-safe fails here,
        // as it would in the runtime — and then let the core Serializer bind
        // them to the constructor by name, the one implementation of that
        // algebra the callback kernel and both adapters also use. A missing
        // required argument is therefore ATOMS-E024, not a bespoke error.
        $wireArgs = Boundary::roundTripNamedArgs($args, $this->serializer);

        $reflection = new \ReflectionClass($jobClass);

        return $reflection->newInstanceArgs(
            $this->serializer->denormalizeNamedArguments($jobClass, $wireArgs),
        );
    }
}
