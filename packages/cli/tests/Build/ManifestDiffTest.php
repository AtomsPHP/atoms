<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Build;

use Atoms\Cli\Build\ManifestDiff;
use PHPUnit\Framework\TestCase;

final class ManifestDiffTest extends TestCase
{
    public function testLabelsAdditiveContractingBreaking(): void
    {
        $from = ['atoms' => [
            ['type' => 'GameRoom', 'methods' => [
                ['name' => 'join', 'params' => [['name' => 'seat', 'type' => 'int']], 'return' => 'void'],
                ['name' => 'leave', 'params' => [], 'return' => 'void'],
            ]],
            ['type' => 'OldRoom', 'methods' => []],
        ]];

        $to = ['atoms' => [
            ['type' => 'GameRoom', 'methods' => [
                ['name' => 'join', 'params' => [['name' => 'seat', 'type' => 'string']], 'return' => 'void'], // breaking
                ['name' => 'score', 'params' => [], 'return' => 'int'],                                        // additive
            ]],
            ['type' => 'Lobby', 'methods' => []], // additive (new type)
        ]];

        $labels = [];
        foreach (ManifestDiff::compare($from, $to) as $change) {
            $labels[$change['detail']] = $change['label'];
        }

        self::assertSame(ManifestDiff::BREAKING, $labels['changed signature GameRoom::join()']);
        self::assertSame(ManifestDiff::ADDITIVE, $labels['new method GameRoom::score()']);
        self::assertSame(ManifestDiff::ADDITIVE, $labels['new atom type Lobby']);
        self::assertSame(ManifestDiff::CONTRACTING, $labels['removed method GameRoom::leave()']);
        self::assertSame(ManifestDiff::CONTRACTING, $labels['removed atom type OldRoom']);
    }

    public function testIdenticalManifestsHaveNoChanges(): void
    {
        $manifest = ['atoms' => [['type' => 'GameRoom', 'methods' => [['name' => 'join', 'params' => [], 'return' => 'void']]]]];

        self::assertSame([], ManifestDiff::compare($manifest, $manifest));
    }
}
