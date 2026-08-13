<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters;

use Atoms\Tests\Integration\Adapters\Host\HostOptions;
use Atoms\Tests\Integration\Adapters\Host\HostRequest;
use Atoms\Tests\Integration\Adapters\Support\CallbackSigner;

/**
 * One row of the adapter conformance table: a callback request to build, and
 * the response shape every host — bare kernel, plain-PHP, Laravel and
 * Symfony — must produce for it.
 *
 * Every row's `$expectedBody` is the EXACT response body (byte for byte) —
 * there is no substring/"contains" mode. A mutation proof (see CallbackCases'
 * class docblock) showed a status+code(+substring) check lets a corrupted
 * kernel error() (garbage appended to every message) through undetected on
 * most rows; exact-body comparison closes that. Rows whose
 * message the kernel builds via {@see \Atoms\Errors\ErrorCatalog::format()}
 * build `$expectedBody` the same way, so a catalog wording change doesn't
 * spuriously break this suite while any drift in the kernel's OWN envelope
 * construction still fails byte-exactly.
 */
final readonly class CallbackCase
{
    /**
     * @param string                                             $key             Stable identifier, used as the DataProvider key and in failure messages.
     * @param 'methods'|'job'|'raw'                              $kind            What the built request's X-Atoms-Kind ends up carrying. Documentation only — $build is authoritative.
     * @param \Closure(CallbackSigner, HostOptions): HostRequest $build           Builds the request against a given host's options; called once per test run of this case.
     * @param int                                                $expectedStatus  The HTTP status every host must answer with.
     * @param string                                              $expectedBody    The exact response body, compared byte for byte.
     * @param list<string>                                       $appliesTo       Capabilities (see AdapterHost::supports()) a host must have for this case to run against it; empty means "every host".
     * @param bool                                                $primeFirst      When true, the SAME built HostRequest is sent through handle() once and discarded before the real (asserted) send — used for nonce-replay (case 5), where the two sends must share one nonce.
     * @param bool                                                $expectQueuedJob When true, assert host->queuedJobs() is non-empty after this case runs.
     * @param bool                                                $expectLogError  When true, assert host->logRecords() contains at least one 'error'-level entry after this case runs.
     */
    public function __construct(
        public string $key,
        public string $kind,
        public \Closure $build,
        public int $expectedStatus,
        public string $expectedBody,
        public array $appliesTo = [],
        public bool $primeFirst = false,
        public bool $expectQueuedJob = false,
        public bool $expectLogError = false,
    ) {
    }
}
