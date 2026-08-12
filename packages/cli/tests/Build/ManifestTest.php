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

    /**
     * The `websocket` key is a claim the runtime acts on: `false` makes
     * `GET /ws/:type/:id` a 501 before any Durable Object is touched, so the
     * build may only assert it when it can actually see that no handler
     * exists. Discovery parses files rather than loading classes, which is
     * exactly why the inherited case has to be an omission rather than a
     * guess.
     */
    public function testWebsocketFlagPerAtomShape(): void
    {
        $manifest = (new Validator())->validate($this->websocketShapesApp())->manifest;

        $byType = [];
        foreach ($manifest['atoms'] as $atom) {
            $byType[$atom['type']] = $atom;
        }

        self::assertSame(
            ['Plain', 'Roomish', 'Subroom', 'Talker'],
            array_keys($byType),
            'every discovered Atom should appear, ordered by FQCN',
        );

        // Declares onConnect itself.
        self::assertTrue($byType['Roomish']['websocket']);
        // Declares onmessage() — PHP method names are case-insensitive, so
        // this overrides Atom::onMessage() and the handler really is reachable.
        self::assertTrue($byType['Talker']['websocket']);
        // Extends Atoms\Atom directly and declares nothing: a claim the build
        // can prove.
        self::assertFalse($byType['Plain']['websocket']);
        // Extends Roomish, which the generator cannot follow: no key at all,
        // which the runtime reads as "allowed".
        self::assertArrayNotHasKey(
            'websocket',
            $byType['Subroom'],
            'an Atom whose parent is not Atoms\\Atom must omit the key, not claim false',
        );
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
