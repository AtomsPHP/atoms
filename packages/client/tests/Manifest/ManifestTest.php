<?php

declare(strict_types=1);

namespace Atoms\Client\Tests\Manifest;

use Atoms\Client\Manifest\ManifestAtom;
use Atoms\Client\Manifest\ManifestLoader;
use PHPUnit\Framework\TestCase;

final class ManifestTest extends TestCase
{
    private function sampleManifest(): array
    {
        return [
            'schema' => 1,
            'project' => ['name' => 'demo'],
            'atoms' => [
                [
                    'type' => 'GameRoom',
                    'class' => 'App\\Atoms\\GameRoom',
                    'methods' => [
                        ['name' => 'add', 'params' => [
                            ['name' => 'a', 'type' => 'int', 'optional' => false],
                            ['name' => 'b', 'type' => '?int', 'optional' => true, 'default' => null],
                        ], 'return' => 'int'],
                    ],
                    'websocket' => true,
                    'migrations' => ['head' => 3, 'files' => []],
                ],
            ],
            'methods' => [
                ['atom_type' => 'GameRoom', 'class' => 'App\\Atoms\\GameRoom\\Methods', 'methods' => [
                    ['name' => 'greet', 'params' => [], 'return' => 'string'],
                ]],
            ],
            'jobs' => [
                ['class' => 'App\\Jobs\\SendWelcome', 'params' => [
                    ['name' => 'playerId', 'type' => 'string'],
                ]],
            ],
            'shared' => [
                ['class' => 'App\\Atoms\\Shared\\PlayerSnapshot', 'properties' => [
                    ['name' => 'name', 'type' => 'string'],
                ]],
            ],
            'toolchain' => ['core_version' => '0.1.0', 'php' => '8.3', 'extensions' => ['sodium'], 'scoper_prefix' => 'AtomsVendor'],
            'content_hash' => 'deadbeefcafe',
        ];
    }

    public function testLoaderParsesValueObjects(): void
    {
        $manifest = (new ManifestLoader())->fromArray($this->sampleManifest());

        self::assertSame(1, $manifest->schema);
        self::assertCount(1, $manifest->atoms);

        $atom = $manifest->atoms[0];
        self::assertInstanceOf(ManifestAtom::class, $atom);
        self::assertSame('GameRoom', $atom->type);
        self::assertTrue($atom->websocket);
        self::assertSame(3, $atom->migrationsHead);
        self::assertCount(1, $atom->methods);
        self::assertSame('add', $atom->methods[0]->name);
        self::assertSame('?int', $atom->methods[0]->params[1]->type);
        self::assertTrue($atom->methods[0]->params[1]->optional);

        self::assertCount(1, $manifest->methods);
        self::assertSame('greet', $manifest->methods[0]->name);
        self::assertCount(1, $manifest->jobs);
        self::assertSame('App\\Jobs\\SendWelcome', $manifest->jobs[0]->class);
        self::assertCount(1, $manifest->shared);
        self::assertSame('deadbeefcafe', $manifest->contentHash);
    }

    public function testHashIsKeyOrderIndependent(): void
    {
        $loader = new ManifestLoader();

        $ordered = $this->sampleManifest();
        $shuffled = $this->deepReverseKeys($ordered);

        $a = $loader->fromArray($ordered)->hash();
        $b = $loader->fromArray($shuffled)->hash();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        self::assertSame($a, $b, 'canonical hash must not depend on key order');
    }

    public function testHashIgnoresContentHashKey(): void
    {
        $loader = new ManifestLoader();

        $withHash = $this->sampleManifest();
        $withoutHash = $this->sampleManifest();
        unset($withoutHash['content_hash']);

        $changed = $this->sampleManifest();
        $changed['content_hash'] = 'totally-different';

        self::assertSame($loader->fromArray($withHash)->hash(), $loader->fromArray($withoutHash)->hash());
        self::assertSame($loader->fromArray($withHash)->hash(), $loader->fromArray($changed)->hash());
    }

    public function testHashChangesWhenContentChanges(): void
    {
        $loader = new ManifestLoader();

        $base = $this->sampleManifest();
        $mutated = $base;
        $mutated['atoms'][0]['type'] = 'OtherRoom';

        self::assertNotSame($loader->fromArray($base)->hash(), $loader->fromArray($mutated)->hash());
    }

    public function testLoadFromFile(): void
    {
        $path = sys_get_temp_dir() . '/atoms-manifest-' . uniqid() . '.json';
        file_put_contents($path, (string) json_encode($this->sampleManifest()));

        try {
            $manifest = (new ManifestLoader())->load($path);
            self::assertSame(1, $manifest->schema);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Recursively reverse associative-array key order (lists left intact) to
     * prove the canonical hash is order-independent.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function deepReverseKeys(array $data): array
    {
        $isList = array_is_list($data);
        $entries = [];
        foreach ($data as $key => $value) {
            $entries[$key] = is_array($value) ? $this->deepReverseKeys($value) : $value;
        }

        return $isList ? $entries : array_reverse($entries, true);
    }
}
