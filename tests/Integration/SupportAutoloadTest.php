<?php

declare(strict_types=1);

namespace Atoms\Tests\Integration;

use App\Atoms\GameRoom;
use App\Atoms\GameRoom\Support\ScoreBoard;
use App\Atoms\Shared\PlayerSnapshot;
use Atoms\Testing\AtomHarness;
use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class SupportAutoloadTest extends TestCase
{
    // Isolate PSR-4 lookup from the Laravel example's same-named GameRoom class.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSampleAppSupportLoadsThroughComposerDuringAHarnessTurn(): void
    {
        $fixture = \dirname(__DIR__, 2) . '/packages/cli/tests/Fixtures/sample-app';
        $composer = json_decode((string) file_get_contents($fixture . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['App\\' => 'app/'], $composer['autoload']['psr-4']);

        $loader = new ClassLoader();
        foreach ($composer['autoload']['psr-4'] as $prefix => $path) {
            $loader->addPsr4($prefix, $fixture . '/' . $path);
        }
        $loader->register(prepend: true);
        $harness = AtomHarness::for(GameRoom::class, 'support-autoload');

        try {
            self::assertFalse(class_exists(ScoreBoard::class, autoload: false));
            self::assertSame([], $loader->getClassMap());
            self::assertContains('Support', scandir($fixture . '/app/Atoms/GameRoom'));

            $player = $harness->invoke('join', [2]);

            self::assertInstanceOf(PlayerSnapshot::class, $player);
            self::assertSame('Ada', $player->name);
            self::assertTrue(class_exists(ScoreBoard::class, autoload: false));
            self::assertSame(
                [['payload' => $player->id]],
                $harness->db()->query('SELECT payload FROM game_room_events'),
            );
        } finally {
            $harness->shutdown();
            $loader->unregister();
        }
    }
}
