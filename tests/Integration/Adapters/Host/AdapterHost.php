<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters\Host;

use Atoms\Tests\Integration\Adapters\Support\FakePsr18Client;

/**
 * One integration under test in the adapter conformance suite: a way to boot
 * a real (framework or framework-free) callback stack in-process and drive it
 * exactly the way its production entry point would be driven, so the SAME
 * case table ({@see \Atoms\Tests\Integration\Adapters\CallbackCases::all()})
 * runs unmodified against every one of them — bare kernel and plain-PHP today
 * (T9a), Laravel and Symfony from T9b.
 */
interface AdapterHost
{
    /**
     * A short, stable identifier for diagnostics (e.g. "bare-kernel").
     */
    public function name(): string;

    public function boot(HostOptions $options): void;

    /**
     * Release anything boot() opened. Safe to call even when boot() opened
     * nothing.
     */
    public function shutdown(): void;

    /**
     * Run $request through the host's real stack — its own router/front
     * controller where it has one ({@see self::supports()} 'routing'), the
     * callback kernel underneath it always — and return the response it
     * produced.
     */
    public function handle(HostRequest $request): HostResponse;

    /**
     * Resolve a service from the host's own container/service map (e.g.
     * `AtomsClient::class`). Throws when the host has no such thing to
     * resolve — check {@see self::supports()} ('client' or 'container')
     * first.
     */
    public function service(string $id): object;

    /**
     * @return list<object> every AtomJob (or recorded equivalent) the host's
     *                       queue bridge has captured since boot().
     */
    public function queuedJobs(): array;

    /**
     * @return list<array{level: string, message: string, context: array<array-key, mixed>}>
     */
    public function logRecords(): array;

    /**
     * The in-memory PSR-18 double standing in for the host's outbound HTTP
     * client, so client-capable hosts' calls can be inspected without ever
     * touching the network.
     */
    public function httpFake(): FakePsr18Client;

    /**
     * @param 'routing'|'container'|'client'|'queue'|'logging' $capability
     */
    public function supports(string $capability): bool;
}
