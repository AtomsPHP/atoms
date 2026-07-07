<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Build;

use Atoms\Cli\Build\ManifestHash;
use Atoms\Cli\Build\Validator;
use Atoms\Cli\Tests\TestCase;

final class ManifestTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return (new Validator())->validate($this->sampleApp())->manifest;
    }

    public function testAtomShape(): void
    {
        $manifest = $this->manifest();
        $atom = $manifest['atoms'][0];

        self::assertSame('GameRoom', $atom['type']);
        self::assertSame('App\\Atoms\\GameRoom', $atom['class']);
        self::assertTrue($atom['websocket']);
        self::assertSame(2, $atom['migrations']['head']);

        $join = $atom['methods'][0];
        self::assertSame('join', $join['name']);
        self::assertSame('?int', $join['params'][0]['type']);
        self::assertFalse($join['params'][0]['optional']);
        self::assertSame('App\\Atoms\\Shared\\PlayerSnapshot', $join['return']);
    }

    public function testMethodsAndJobsAndShared(): void
    {
        $manifest = $this->manifest();

        self::assertSame('getPlayer', $manifest['methods'][0]['methods'][0]['name']);
        self::assertSame('GameRoom', $manifest['methods'][0]['atom_type']);

        $job = $manifest['jobs'][0];
        self::assertSame('App\\Atoms\\Jobs\\RecordGameResult', $job['class']);
        self::assertSame('ref', $job['params'][0]['name']);
        self::assertSame('string', $job['params'][0]['type']);
        self::assertSame('int', $job['params'][1]['type']);

        $shared = $manifest['shared'][0];
        self::assertSame('App\\Atoms\\Shared\\PlayerSnapshot', $shared['class']);
        self::assertSame(['id', 'name', 'elo'], array_column($shared['properties'], 'name'));
    }

    public function testManifestHashIsKeyOrderIndependent(): void
    {
        $manifest = $this->manifest();
        $reordered = $this->deepReverse($manifest);

        self::assertNotSame(
            array_keys($manifest),
            array_keys($reordered),
            'the reordered manifest should differ in key order',
        );
        self::assertSame(ManifestHash::of($manifest), ManifestHash::of($reordered));
    }

    public function testContentHashKeyIsExcludedFromManifestHash(): void
    {
        $manifest = $this->manifest();
        $withHash = $manifest;
        $withHash['content_hash'] = 'deadbeef';

        self::assertSame(ManifestHash::of($manifest), ManifestHash::of($withHash));
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function deepReverse(array $value): array
    {
        $out = array_reverse($value, true);
        foreach ($out as $k => $v) {
            if (\is_array($v) && !array_is_list($v)) {
                $out[$k] = $this->deepReverse($v);
            }
        }

        return $out;
    }
}
