<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration\Adapters;

use Atoms\Tests\Integration\Adapters\Fixtures\RecordScoreJob;
use Atoms\Tests\Integration\Adapters\Host\HostOptions;
use Atoms\Tests\Integration\Adapters\Host\HostRequest;
use Atoms\Tests\Integration\Adapters\Support\CallbackSigner;

/**
 * The envelope table: ONE set of callback cases, run unmodified against
 * every host. Expected bodies/codes here are the CONTRACT — verified against
 * the real {@see \Atoms\Client\Callback\CallbackKernel} via BareKernelHost
 * while this suite was built. If a host's actual output ever disagrees with a
 * row, that is a real finding about the host, not a reason to edit the row.
 *
 * @see docs/conventions.md §Callback signing for the wire shapes these rows encode.
 */
final class CallbackCases
{
    private function __construct()
    {
    }

    /**
     * @return list<CallbackCase>
     */
    public static function all(): array
    {
        return [
            self::methodsHappyAdd(),
            self::payloadReturn(),
            self::tamperedSignature(),
            self::staleTimestamp(),
            self::replayedNonce(),
            self::unknownKind(),
            self::unknownType(),
            self::unknownMethod(),
            self::argMismatch(),
            self::jobHappy(),
            self::jobClassNotAJob(),
            self::customerExceptionBoom(),
            self::malformedJsonBody(),
            self::noSigningHeaders(),
            self::byteRoundTrip(),
        ];
    }

    /**
     * Build a fully signed HostRequest — the same mechanics every case below
     * uses — exposed publicly so dedicated tests (M2/M4/S1/S4/S5/S7) and
     * future hosts never have to re-derive the envelope shape.
     */
    public static function signedRequest(
        CallbackSigner $signer,
        HostOptions $options,
        string $kind,
        string $body,
        ?int $timestampOffset = null,
        ?string $nonce = null,
        ?string $signatureOverride = null,
    ): HostRequest {
        $timestamp = (string) (time() + ($timestampOffset ?? 0));
        $nonce ??= $signer->newNonce();
        $headers = $signer->sign($timestamp, $nonce, $body, $kind);

        if ($signatureOverride !== null) {
            $headers['X-Atoms-Signature'] = $signatureOverride;
        }

        return new HostRequest('POST', $options->callbackPath, $headers, $body);
    }

    /**
     * @param list<mixed> $args
     */
    public static function methodsBody(string $atomType, string $atomId, string $method, array $args): string
    {
        return (string) json_encode([
            'atom' => ['type' => $atomType, 'id' => $atomId],
            'method' => $method,
            'args' => $args,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function jobBody(string $jobClass, array $args): string
    {
        return (string) json_encode([
            'job' => $jobClass,
            'args' => $args,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Case 1: methods happy path — add(2, 3).
     */
    private static function methodsHappyAdd(): CallbackCase
    {
        $body = self::methodsBody('GameRoom', 'g-1', 'add', [2, 3]);

        return new CallbackCase(
            key: 'methods-happy-add',
            kind: 'methods',
            build: self::builder('methods', $body),
            expectedStatus: 200,
            expectedBody: '{"result":5}',
            bodyAssertion: 'exact',
        );
    }

    /**
     * Case 2: a Methods return value that is itself a Payload normalizes to
     * a JSON object keyed by promoted-constructor-parameter order.
     */
    private static function payloadReturn(): CallbackCase
    {
        $body = self::methodsBody('GameRoom', 'g-1', 'snapshot', []);

        return new CallbackCase(
            key: 'payload-return',
            kind: 'methods',
            build: self::builder('methods', $body),
            expectedStatus: 200,
            expectedBody: '{"result":{"name":"ada","score":7}}',
            bodyAssertion: 'exact',
        );
    }

    /**
     * Case 3: a tampered signature is rejected before any customer code runs.
     */
    private static function tamperedSignature(): CallbackCase
    {
        $body = self::methodsBody('GameRoom', 'g-1', 'add', [2, 3]);

        return new CallbackCase(
            key: 'tampered-signature',
            kind: 'methods',
            build: self::builder(
                'methods',
                $body,
                signatureOverride: base64_encode(str_repeat("\x01", SODIUM_CRYPTO_SIGN_BYTES)),
            ),
            expectedStatus: 401,
            expectedBody: null,
            bodyAssertion: 'jsonCode',
            expectedErrorCode: 'ATOMS-E064',
        );
    }

    /**
     * Case 4: a timestamp far outside the skew window is rejected.
     */
    private static function staleTimestamp(): CallbackCase
    {
        $body = self::methodsBody('GameRoom', 'g-1', 'add', [2, 3]);

        return new CallbackCase(
            key: 'stale-timestamp',
            kind: 'methods',
            build: self::builder('methods', $body, timestampOffset: -4000),
            expectedStatus: 401,
            expectedBody: null,
            bodyAssertion: 'jsonCode',
            expectedErrorCode: 'ATOMS-E065',
        );
    }

    /**
     * Case 5: the same signed request sent twice — the second send is a
     * replay. primeFirst tells the test runner to send the one built
     * HostRequest through handle() twice, so both sends share one nonce.
     */
    private static function replayedNonce(): CallbackCase
    {
        $body = self::methodsBody('GameRoom', 'g-1', 'add', [2, 3]);

        return new CallbackCase(
            key: 'replayed-nonce',
            kind: 'methods',
            build: self::builder('methods', $body),
            expectedStatus: 401,
            expectedBody: null,
            bodyAssertion: 'jsonCode',
            expectedErrorCode: 'ATOMS-E065',
            primeFirst: true,
        );
    }

    /**
     * Case 6: a kind the kernel does not recognize.
     */
    private static function unknownKind(): CallbackCase
    {
        return new CallbackCase(
            key: 'unknown-kind',
            kind: 'raw',
            build: self::builder('bogus', '{}'),
            expectedStatus: 422,
            expectedBody: null,
            bodyAssertion: 'jsonCode',
            expectedErrorCode: 'ATOMS-E066',
            expectedMessageContains: ["Unknown callback kind 'bogus'"],
        );
    }

    /**
     * Case 7: an Atom type with no resolvable Methods class.
     */
    private static function unknownType(): CallbackCase
    {
        $body = self::methodsBody('NoSuchRoom', 'x', 'add', []);

        return new CallbackCase(
            key: 'unknown-type',
            kind: 'methods',
            build: self::builder('methods', $body),
            expectedStatus: 422,
            expectedBody: null,
            bodyAssertion: 'jsonCode',
            expectedErrorCode: 'ATOMS-E066',
        );
    }

    /**
     * Case 8: a resolvable Methods class with no such method.
     */
    private static function unknownMethod(): CallbackCase
    {
        $body = self::methodsBody('GameRoom', 'g-1', 'nope', []);

        return new CallbackCase(
            key: 'unknown-method',
            kind: 'methods',
            build: self::builder('methods', $body),
            expectedStatus: 422,
            expectedBody: null,
            bodyAssertion: 'jsonCode',
            expectedErrorCode: 'ATOMS-E030',
        );
    }

    /**
     * Case 9: argument type mismatch against the declared signature.
     */
    private static function argMismatch(): CallbackCase
    {
        $body = self::methodsBody('GameRoom', 'g-1', 'add', ['x', 3]);

        return new CallbackCase(
            key: 'arg-mismatch',
            kind: 'methods',
            build: self::builder('methods', $body),
            expectedStatus: 422,
            expectedBody: null,
            bodyAssertion: 'jsonCode',
            expectedErrorCode: 'ATOMS-E024',
        );
    }

    /**
     * Case 10: job happy path — reconstructed and handed to the queue bridge.
     */
    private static function jobHappy(): CallbackCase
    {
        $body = self::jobBody(RecordScoreJob::class, ['playerId' => 'p1', 'score' => 100]);

        return new CallbackCase(
            key: 'job-happy',
            kind: 'job',
            build: self::builder('job', $body),
            expectedStatus: 200,
            expectedBody: '{"queued":true}',
            bodyAssertion: 'exact',
            appliesTo: ['queue'],
            expectQueuedJob: true,
        );
    }

    /**
     * Case 11: a job class that is not an AtomJob subclass.
     */
    private static function jobClassNotAJob(): CallbackCase
    {
        $body = self::jobBody(\stdClass::class, []);

        return new CallbackCase(
            key: 'job-class-not-a-job',
            kind: 'job',
            build: self::builder('job', $body),
            expectedStatus: 422,
            expectedBody: null,
            bodyAssertion: 'jsonCode',
            expectedErrorCode: 'ATOMS-E033',
        );
    }

    /**
     * Case 12: customer code throws — sanitized 500, plus an error log entry
     * (S5 re-drives this to check the log record's context keys).
     */
    private static function customerExceptionBoom(): CallbackCase
    {
        $body = self::methodsBody('GameRoom', 'g-1', 'boom', []);

        return new CallbackCase(
            key: 'customer-exception-boom',
            kind: 'methods',
            build: self::builder('methods', $body),
            expectedStatus: 500,
            expectedBody: null,
            bodyAssertion: 'jsonCode',
            expectedErrorCode: 'internal',
            expectedMessageContains: ['RuntimeException', 'boom'],
            expectLogError: true,
        );
    }

    /**
     * Case 13: a correctly signed but syntactically invalid JSON body.
     */
    private static function malformedJsonBody(): CallbackCase
    {
        return new CallbackCase(
            key: 'malformed-json-body',
            kind: 'raw',
            build: self::builder('methods', '{'),
            expectedStatus: 400,
            expectedBody: null,
            bodyAssertion: 'jsonCode',
            expectedErrorCode: 'ATOMS-E064',
            expectedMessageContains: ['not valid JSON'],
        );
    }

    /**
     * Case 14: no signing headers at all.
     */
    private static function noSigningHeaders(): CallbackCase
    {
        $body = self::methodsBody('GameRoom', 'g-1', 'add', [2, 3]);

        return new CallbackCase(
            key: 'no-signing-headers',
            kind: 'raw',
            build: static fn (CallbackSigner $signer, HostOptions $options): HostRequest =>
                new HostRequest('POST', $options->callbackPath, [], $body),
            expectedStatus: 401,
            expectedBody: null,
            bodyAssertion: 'jsonCode',
            expectedErrorCode: 'ATOMS-E064',
        );
    }

    /**
     * Case 15: byte-preservation proof. The body is built by hand (not via
     * json_encode's defaults) so it carries a raw '/', a raw 'é', and a
     * trailing newline — all inside the (unread) `atom.id` field — and is
     * signed over those EXACT bytes. If a host re-encoded the body anywhere
     * on its way to the kernel, the signature would no longer verify and
     * this would fail with 401 ATOMS-E064 instead of 200.
     */
    private static function byteRoundTrip(): CallbackCase
    {
        $body = '{"atom":{"type":"GameRoom","id":"room/é"},"method":"add","args":[2,3]}' . "\n";

        return new CallbackCase(
            key: 'byte-round-trip',
            kind: 'methods',
            build: self::builder('methods', $body),
            expectedStatus: 200,
            expectedBody: '{"result":5}',
            bodyAssertion: 'exact',
        );
    }

    private static function builder(
        string $kind,
        string $body,
        ?int $timestampOffset = null,
        ?string $signatureOverride = null,
    ): \Closure {
        return static fn (CallbackSigner $signer, HostOptions $options): HostRequest =>
            self::signedRequest($signer, $options, $kind, $body, $timestampOffset, null, $signatureOverride);
    }
}
