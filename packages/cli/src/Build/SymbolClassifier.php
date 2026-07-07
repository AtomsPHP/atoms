<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use Atoms\Cli\Config\AllowedPackages;
use Atoms\Errors\ErrorCode;

/**
 * Stage 3 of the pipeline: given a symbol referenced from Atom/Shared code,
 * decide whether it is provided by the runtime (core / bundled / vendor-approved
 * / stdlib) or a boundary violation (an ATOMS-E01x code).
 */
final class SymbolClassifier
{
    private const FRAMEWORK_PREFIXES = ['Illuminate\\', 'Laravel\\', 'Symfony\\'];

    /** Global helpers that only exist inside a web framework. */
    private const GLOBAL_HELPERS = [
        'config', 'app', 'resolve', 'auth', 'cache', 'session', 'request',
        'response', 'route', 'view', 'event', 'broadcast', 'now', 'collect',
        'dispatch', 'logger', 'abort', 'url', 'redirect', 'trans', '__',
        'report', 'retry', 'cookie', 'encrypt', 'decrypt', 'validator',
    ];

    /** @var list<string> approved namespace prefixes from atoms-composer.json */
    private array $approvedNamespaces;

    /** @var array<string, true> lowercased allowed extension names */
    private array $runtimeExtensions;

    /** @var array<string, true> extensions actually referenced by Atom/Shared code */
    private array $usedExtensions = [];

    /**
     * @param list<string> $approvedNamespaces
     */
    public function __construct(
        private readonly DiscoveryResult $discovery,
        private readonly AllowedPackages $allowedPackages,
        array $approvedNamespaces,
        ?string $extensionsResource = null,
    ) {
        $this->approvedNamespaces = $approvedNamespaces;
        $this->runtimeExtensions = self::loadExtensions($extensionsResource);
    }

    /**
     * @return array<string, true> lowercased extension name => true
     */
    private static function loadExtensions(?string $path): array
    {
        $path ??= __DIR__ . '/../../resources/runtime-extensions.json';
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Could not read runtime-extensions.json at {$path}");
        }
        /** @var array{extensions?: list<string>} $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $out = [];
        foreach ($decoded['extensions'] ?? [] as $ext) {
            $out[strtolower($ext)] = true;
        }

        return $out;
    }

    /**
     * @return list<string> sorted extension names used by classified symbols
     */
    public function usedExtensions(): array
    {
        $names = array_keys($this->usedExtensions);
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Classify a reference. Returns a Violation for boundary breaks, or null when
     * the symbol is legal in Atom code.
     *
     * @param bool $shared true when the referencing class is a Shared DTO
     */
    public function classify(
        string $name,
        SymbolKind $kind,
        string $file,
        int $line,
        bool $shared = false,
        string $owner = '',
    ): ?Violation {
        $name = ltrim($name, '\\');

        if ($name === '' || \in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
            return null;
        }

        if ($kind === SymbolKind::FunctionCall) {
            return $this->classifyFunction($name, $file, $line);
        }

        return $this->classifyClass($name, $file, $line, $shared, $owner);
    }

    private function classifyFunction(string $name, string $file, int $line): ?Violation
    {
        $lower = strtolower($name);

        if ($lower === 'env') {
            return new Violation(ErrorCode::EnvInAtom, $file, $line, [], $name);
        }
        if ($lower === 'serialize' || $lower === 'unserialize') {
            return new Violation(ErrorCode::NativeSerializationAtBoundary, $file, $line, [], $name);
        }
        if (!str_contains($name, '\\') && \in_array($lower, self::GLOBAL_HELPERS, true)) {
            return new Violation(ErrorCode::FrameworkHelperInAtom, $file, $line, ['symbol' => $name], $name);
        }

        // Namespaced function: treat like a namespaced symbol.
        if (str_contains($name, '\\')) {
            return $this->classifyNamespaced($name, $file, $line, SymbolKind::FunctionCall, false, '');
        }

        // Global, unqualified: allowed if it is a runtime-provided builtin.
        if (\function_exists($name)) {
            return $this->checkExtension(
                (new \ReflectionFunction($name))->getExtensionName(),
                $name,
                $file,
                $line,
            );
        }

        // Unknown global function — assume a user global helper; do not flag.
        return null;
    }

    private function classifyClass(string $name, string $file, int $line, bool $shared, string $owner): ?Violation
    {
        if (str_starts_with($name, 'Atoms\\')) {
            return null; // core: provided by the runtime
        }

        if ($this->discovery->has($name)) {
            $bundled = $this->discovery->get($name);
            // A Shared DTO may only reference core + stdlib + other Shared DTOs.
            if ($shared && $bundled !== null && $bundled->kind !== ClassKind::Shared) {
                return $this->sharedViolation($name, $owner, $file, $line);
            }

            return null; // bundled: ships in the closure
        }

        if (str_contains($name, '\\')) {
            return $this->classifyNamespaced($name, $file, $line, SymbolKind::ClassLike, $shared, $owner);
        }

        // Global-namespace class: allowed only if a runtime builtin.
        if (class_exists($name, false) || interface_exists($name, false)
            || enum_exists($name, false) || trait_exists($name, false)) {
            return $this->checkExtension(
                (new \ReflectionClass($name))->getExtensionName(),
                $name,
                $file,
                $line,
            );
        }

        // A bare, unknown, un-namespaced class — treat as a monolith reference.
        return new Violation(ErrorCode::MonolithClassInAtom, $file, $line, ['symbol' => $name], $name);
    }

    private function classifyNamespaced(
        string $name,
        string $file,
        int $line,
        SymbolKind $kind,
        bool $shared,
        string $owner,
    ): ?Violation {
        // Approved vendor namespace declared in atoms-composer.json → allowed in
        // Atom code, but Shared DTOs may use core + stdlib only.
        foreach ($this->approvedNamespaces as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return $shared ? $this->sharedViolation($name, $owner, $file, $line) : null;
            }
        }

        if (str_contains($name, '\\Facades\\')) {
            return new Violation(ErrorCode::FacadeInAtom, $file, $line, ['symbol' => $name], $name);
        }

        foreach (self::FRAMEWORK_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return new Violation(ErrorCode::FrameworkSymbolInAtom, $file, $line, ['symbol' => $name], $name);
            }
        }

        // Known package on the allowlist but not declared in atoms-composer.json.
        $package = $this->allowedPackages->packageForSymbol($name);
        if ($package !== null) {
            return new Violation(
                ErrorCode::UndeclaredPackage,
                $file,
                $line,
                ['symbol' => $name, 'package' => $package],
                $name,
            );
        }

        // Namespaced but internal (e.g. a global-namespaced extension class)?
        if ($kind === SymbolKind::ClassLike
            && (class_exists($name, false) || interface_exists($name, false)
                || enum_exists($name, false) || trait_exists($name, false))) {
            return $this->checkExtension(
                (new \ReflectionClass($name))->getExtensionName(),
                $name,
                $file,
                $line,
            );
        }

        return new Violation(ErrorCode::MonolithClassInAtom, $file, $line, ['symbol' => $name], $name);
    }

    private function sharedViolation(string $symbol, string $owner, string $file, int $line): Violation
    {
        return new Violation(
            ErrorCode::SharedNonCoreSymbol,
            $file,
            $line,
            ['class' => $owner, 'symbol' => $symbol],
            $symbol,
        );
    }

    private function checkExtension(?string $extension, string $name, string $file, int $line): ?Violation
    {
        if ($extension === null || $extension === '') {
            return null; // user-defined / engine core without extension metadata
        }

        $lower = strtolower($extension);
        if (isset($this->runtimeExtensions[$lower])) {
            $this->usedExtensions[$lower] = true;

            return null;
        }

        return new Violation(
            ErrorCode::ExtensionUnavailable,
            $file,
            $line,
            ['extension' => $extension],
            $name,
        );
    }
}
