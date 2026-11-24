<?php

declare(strict_types=1);

namespace Atoms\Cli\Tests\Build;

use Atoms\Cli\Build\DiscoveryResult;
use Atoms\Cli\Build\SymbolClassifier;
use Atoms\Cli\Build\SymbolKind;
use Atoms\Cli\Config\AllowedPackages;
use Atoms\Cli\Tests\TestCase;
use Atoms\Errors\ErrorCode;

final class SymbolClassifierTest extends TestCase
{
    public function testTracksWorkerExtensionsAndRejectsInternalWasmFunctions(): void
    {
        $classifier = new SymbolClassifier(new DiscoveryResult([], []), AllowedPackages::load(), []);

        self::assertNull($classifier->classify('bcadd', SymbolKind::FunctionCall, 'Room.php', 1));
        self::assertNull($classifier->classify('CurlHandle', SymbolKind::ClassLike, 'Room.php', 2));
        self::assertNull($classifier->classify('PDO', SymbolKind::ClassLike, 'Room.php', 3));
        self::assertSame(['bcmath', 'curl', 'pdo'], $classifier->usedExtensions());

        $error = $classifier->classify('post_message_to_js', SymbolKind::FunctionCall, 'Room.php', 4);
        self::assertSame(ErrorCode::ExtensionUnavailable, $error?->code);
    }

    public function testClassAvailabilityFollowsTheWorkerInventory(): void
    {
        $inventory = $this->freshDir() . '/extensions.json';
        file_put_contents($inventory, json_encode([
            'extensions' => ['core' => '8.3.32'],
            'classes' => ['guest\\sample' => 'core'],
        ], JSON_THROW_ON_ERROR));
        $classifier = new SymbolClassifier(new DiscoveryResult([], []), AllowedPackages::load(), [], $inventory);

        self::assertNull($classifier->classify('Guest\\Sample', SymbolKind::ClassLike, 'Room.php', 1));
        $missingClass = $classifier->classify('Exception', SymbolKind::ClassLike, 'Room.php', 2);
        self::assertSame(ErrorCode::MonolithClassInAtom, $missingClass?->code);

        $missingExtension = $classifier->classify('DateTime', SymbolKind::ClassLike, 'Room.php', 3);
        self::assertSame(ErrorCode::ExtensionUnavailable, $missingExtension?->code);
        self::assertSame('date', $missingExtension?->context['extension']);
    }
}
