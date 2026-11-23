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
    public function testRuntimeExtensionAndFunctionInventoriesStayConsistent(): void
    {
        $resources = \dirname(__DIR__, 2) . '/resources';
        /** @var array{extensions: list<string>} $extensionsData */
        $extensionsData = json_decode(
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

        $extensions = $extensionsData['extensions'];
        $sorted = $extensions;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $extensions, 'runtime-extensions.json must stay sorted');
        self::assertSame($extensions, array_values(array_unique($extensions)));

        $extensionSet = array_fill_keys($extensions, true);
        foreach ($functions as $function => $extension) {
            self::assertArrayHasKey(
                $extension,
                $extensionSet,
                "Function {$function}() references extension {$extension} missing from runtime-extensions.json",
            );
        }

        foreach (['bcmath', 'iconv', 'openssl', 'pdo', 'pdo_sqlite', 'reflection', 'xml', 'zend opcache', 'zip', 'zlib'] as $expected) {
            self::assertArrayHasKey($expected, $extensionSet);
        }
        foreach (['pcntl', 'posix', 'sockets', 'uv', 'dns_polyfill', 'post_message_to_js', 'wasm_memory_storage'] as $absent) {
            self::assertArrayNotHasKey($absent, $extensionSet);
        }
    }

    public function testAllowsBundledWorkerFunctionsAndClassesAndRecordsUsedExtensions(): void
    {
        $classifier = new SymbolClassifier(new DiscoveryResult([], []), AllowedPackages::load(), []);

        foreach (['bcadd', 'openssl_encrypt', 'iconv', 'gzcompress', 'simplexml_load_string', 'token_get_all'] as $fn) {
            self::assertNull(
                $classifier->classify($fn, SymbolKind::FunctionCall, 'app/Atoms/Room.php', 10),
                "Expected Worker builtin {$fn}() to be allowed",
            );
        }

        foreach (['PDO', 'ReflectionClass'] as $class) {
            self::assertNull(
                $classifier->classify($class, SymbolKind::ClassLike, 'app/Atoms/Room.php', 12),
                "Expected Worker builtin class {$class} to be allowed",
            );
        }

        self::assertSame(
            ['bcmath', 'iconv', 'openssl', 'pdo', 'reflection', 'simplexml', 'tokenizer', 'zlib'],
            $classifier->usedExtensions(),
        );
    }

    public function testRejectsUnavailableExtensionsWithE019(): void
    {
        $tempDir = $this->freshDir();
        $restricted = $tempDir . '/runtime-extensions.json';
        file_put_contents($restricted, json_encode([
            'extensions' => ['core', 'standard'],
        ], JSON_THROW_ON_ERROR));

        $classifier = new SymbolClassifier(new DiscoveryResult([], []), AllowedPackages::load(), [], $restricted);

        $fnViolation = $classifier->classify('json_encode', SymbolKind::FunctionCall, 'app/Atoms/Room.php', 14);
        self::assertNotNull($fnViolation);
        self::assertSame(ErrorCode::ExtensionUnavailable, $fnViolation->code);
        self::assertSame('json', $fnViolation->context['extension'] ?? null);

        $classViolation = $classifier->classify('PDO', SymbolKind::ClassLike, 'app/Atoms/Room.php', 15);
        self::assertNotNull($classViolation);
        self::assertSame(ErrorCode::ExtensionUnavailable, $classViolation->code);
        self::assertSame('PDO', $classViolation->context['extension'] ?? null);
    }

    public function testRejectsRemovedSocketClassAndInternalWasmFunctions(): void
    {
        $classifier = new SymbolClassifier(new DiscoveryResult([], []), AllowedPackages::load(), []);

        foreach (['post_message_to_js', 'checkdnsrr', 'dns_get_record', 'pcntl_fork', 'posix_getpid'] as $fn) {
            $violation = $classifier->classify($fn, SymbolKind::FunctionCall, 'app/Atoms/Room.php', 20);
            self::assertNotNull($violation, "Expected {$fn}() to be rejected");
            self::assertSame(ErrorCode::UnavailableWorkerFunction, $violation->code);
        }

        if (class_exists('Socket', false)) {
            $socketViolation = $classifier->classify('Socket', SymbolKind::ClassLike, 'app/Atoms/Room.php', 21);
            self::assertNotNull($socketViolation);
            self::assertSame(ErrorCode::ExtensionUnavailable, $socketViolation->code);
            self::assertSame('sockets', $socketViolation->context['extension'] ?? null);
        }
    }
}
