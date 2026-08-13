<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters;

use Atoms\Tests\Integration\Adapters\Host\HostOptions;
use Atoms\Tests\Integration\Adapters\Host\HostRequest;
use Atoms\Tests\Integration\Adapters\Support\CallbackSigner;

/**
 * One row of the adapter conformance table: a callback request to build, and
 * the response shape every host — bare kernel and plain-PHP today, Laravel
 * and Symfony from T9b — must produce for it.
 */
final readonly class CallbackCase
{
    /**
     * @param string                                               $key                     Stable identifier, used as the DataProvider key and in failure messages.
     * @param 'methods'|'job'|'raw'                                $kind                    What the built request's X-Atoms-Kind ends up carrying. Documentation only — $build is authoritative.
     * @param \Closure(CallbackSigner, HostOptions): HostRequest   $build                   Builds the request against a given host's options; called once per test run of this case.
     * @param int                                                  $expectedStatus          The HTTP status every host must answer with.
     * @param string|null                                          $expectedBody            The exact response body, consulted only when $bodyAssertion is 'exact'.
     * @param 'exact'|'jsonCode'                                    $bodyAssertion           'exact' compares $expectedBody literally (byte for byte). 'jsonCode' checks status + error.code (+ optional message substrings) — NOT the whole formatted text — for cases whose message carries dynamic content.
     * @param string|null                                          $expectedErrorCode       error.code to assert in 'jsonCode' mode (an ATOMS-E### catalog code, or a bare wire code like "internal").
     * @param list<string>                                         $expectedMessageContains Substrings the error/exception message must contain. Only consulted in 'jsonCode' mode.
     * @param list<string>                                         $appliesTo               Capabilities (see AdapterHost::supports()) a host must have for this case to run against it; empty means "every host".
     * @param bool                                                  $primeFirst              When true, the SAME built HostRequest is sent through handle() once and discarded before the real (asserted) send — used for nonce-replay (case 5), where the two sends must share one nonce.
     * @param bool                                                  $expectQueuedJob         When true, assert host->queuedJobs() is non-empty after this case runs.
     * @param bool                                                  $expectLogError          When true, assert host->logRecords() contains at least one 'error'-level entry after this case runs.
     */
    public function __construct(
        public string $key,
        public string $kind,
        public \Closure $build,
        public int $expectedStatus,
        public ?string $expectedBody,
        public string $bodyAssertion,
        public ?string $expectedErrorCode = null,
        public array $expectedMessageContains = [],
        public array $appliesTo = [],
        public bool $primeFirst = false,
        public bool $expectQueuedJob = false,
        public bool $expectLogError = false,
    ) {
    }
}
