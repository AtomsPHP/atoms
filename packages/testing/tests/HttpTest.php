<?php

declare(strict_types=1);

namespace Atoms\Testing\Tests;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;
use Atoms\Http\HttpResponse;
use Atoms\Testing\AtomHarness;
use Atoms\Testing\Tests\Fixtures\HttpAtom;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    public function testScriptsIncludeActivationAndRecordAttemptsInOrder(): void
    {
        $response = new HttpResponse(409, ['Set-Cookie' => ['a=1'], 'set-cookie' => ['b=2']], "\xff");
        self::assertSame(['set-cookie' => ['a=1', 'b=2']], $response->headers);
        $failure = new \RuntimeException('scripted failure');
        $harness = AtomHarness::for(HttpAtom::class, 'one')
            ->withHttp('HEAD', HttpAtom::URL, new HttpResponse(204))
            ->withHttp('POST', HttpAtom::URL, $response)
            ->withHttp('POST', HttpAtom::URL, $failure);

        $atom = $harness->atom();
        self::assertSame($response, $atom->send(['x-field' => ['a', 'b']], "\0\xff"));
        try {
            $atom->send();
            self::fail('Expected the scripted failure');
        } catch (\RuntimeException $e) {
            self::assertSame($failure, $e);
        }
        try {
            $atom->send();
            self::fail('Expected an unmatched request');
        } catch (AtomsError $e) {
            self::assertSame(ErrorCode::HttpUnstubbed, $e->errorCode);
        }
        $requests = $harness->httpRequests();
        self::assertSame(['HEAD', 'POST', 'POST', 'POST'], array_column($requests, 'method'));
        self::assertSame([['x-field', 'a'], ['x-field', 'b']], $requests[1]['headers']);
        self::assertSame("\0\xff", $requests[1]['body']);
    }

    public function testCallerCanChooseADifferentDestinationOnEachRequest(): void
    {
        $urls = ['https://webhook.example.org/event', 'https://localhost:8443/status', 'https://[::1]/'];
        $harness = AtomHarness::for(HttpAtom::class, 'one')
            ->withHttp('HEAD', HttpAtom::URL, new HttpResponse(204));
        foreach ($urls as $url) {
            $harness->withHttp('POST', $url, new HttpResponse(202));
        }

        $atom = $harness->atom();
        foreach ($urls as $url) {
            self::assertSame(202, $atom->send(url: $url)->status);
        }
        self::assertSame([HttpAtom::URL, ...$urls], array_column($harness->httpRequests(), 'url'));
    }

    public function testProtectedHttpAccessorIsUnavailableThroughInvoke(): void
    {
        $harness = AtomHarness::for(HttpAtom::class, 'one')
            ->withHttp('HEAD', HttpAtom::URL, new HttpResponse(204));

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('protected method');
        $harness->invoke('http');
    }

    public function testRefusalsPreserveTheNextScriptedResponse(): void
    {
        $harness = AtomHarness::for(HttpAtom::class, 'one')
            ->withHttp('HEAD', HttpAtom::URL, new HttpResponse(204))
            ->withHttp('POST', HttpAtom::URL, new HttpResponse(202));
        $atom = $harness->atom();
        foreach ([
            [fn() => $atom->send(['Host' => 'somewhere']), ErrorCode::HttpHeaderDenied],
            [fn() => $atom->send(['x-field' => "one\ntwo"]), ErrorCode::HttpInvalidRequest],
            [fn() => $harness->db()->transaction(fn() => $atom->send()), ErrorCode::HttpInTransaction],
        ] as [$call, $code]) {
            try {
                $call();
                self::fail('Expected a refusal');
            } catch (AtomsError $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
        $pdo = $harness->db()->pdo();
        $pdo->beginTransaction();
        try {
            $atom->send();
            self::fail('Expected a direct PDO transaction to refuse HTTP');
        } catch (AtomsError $e) {
            self::assertSame(ErrorCode::HttpInTransaction, $e->errorCode);
        } finally {
            $pdo->rollBack();
        }
        self::assertCount(1, $harness->httpRequests());
        self::assertSame(202, $atom->send()->status);
    }
}
