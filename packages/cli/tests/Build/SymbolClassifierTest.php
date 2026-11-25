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

        foreach (['post_message_to_js', 'checkdnsrr', 'dns_check_record', 'dns_get_mx', 'dns_get_record', 'getmxrr'] as $fn) {
            $error = $classifier->classify($fn, SymbolKind::FunctionCall, 'Room.php', 4);
            self::assertSame(ErrorCode::UnavailableWorkerFunction, $error?->code, "Expected {$fn}() to report ATOMS-E004");
        }
    }

    public function testRuntimeInventoriesOnlyReferenceSupportedWorkerExtensions(): void
    {
        $resources = \dirname(__DIR__, 2) . '/resources';
        /** @var array{extensions: array<string, string>, classes: array<string, string>} $inventory */
        $inventory = json_decode(
            (string) file_get_contents($resources . '/runtime-extensions.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        /** @var array<string, string> $functions */
        $functions = json_decode(
            (string) file_get_contents($resources . '/runtime-functions.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($functions as $function => $extension) {
            self::assertArrayHasKey(
                $extension,
                $inventory['extensions'],
                "Function {$function}() references unlisted extension {$extension}",
            );
        }
        foreach ($inventory['classes'] as $class => $extension) {
            self::assertArrayHasKey(
                $extension,
                $inventory['extensions'],
                "Class {$class} references unlisted extension {$extension}",
            );
        }
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
