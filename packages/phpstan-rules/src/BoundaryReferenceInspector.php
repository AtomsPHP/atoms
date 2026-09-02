<?php

declare(strict_types=1);

namespace Atoms\PHPStan;

use Atoms\Errors\ErrorCode;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;

/**
 * Shared legality check behind BoundaryNewRule, BoundaryStaticCallRule,
 * BoundaryClassConstRule, and BoundaryInstanceofRule: given a class name
 * referenced from ATOM_SIDE or SHARED code, decide whether the reference is
 * legal, and if not, which ATOMS-E0xx code and message context apply.
 *
 * Legal iff the referenced name:
 *  - has the `Atoms\` prefix (the runtime API itself), or
 *  - resolves to a class that is itself ATOM_SIDE or SHARED, or whose own file
 *    sits under a configured Atoms path (even if its own classification is
 *    OTHER — e.g. a plain helper class colocated with an Atom), or
 *  - is a PHP builtin (resolvable and ClassReflection::isBuiltin()), or is
 *    unresolvable and carries no namespace (assume a global builtin/polyfill
 *    rather than false-positive on it), or
 *  - matches one of the configured `allowedNamespaces`.
 *
 * Illegal references are categorized:
 *  - a Facade (name contains `\Facades\`, or resolvable and a subclass of
 *    Illuminate\Support\Facades\Facade) → ATOMS-E014
 *  - an `Illuminate\`/`Laravel\` symbol → ATOMS-E010
 *  - anything else → ATOMS-E012 (monolith class) in ATOM_SIDE
 *
 * In SHARED context, any illegal reference is reported as ATOMS-E015
 * instead — Shared code has one purity rule, not three.
 */
final class BoundaryReferenceInspector
{
    private const FACADE_BASE = 'Illuminate\Support\Facades\Facade';

    public function __construct(
        private readonly AtomsRulesConfig $config,
        private readonly SideClassifier $classifier,
        private readonly ReflectionProvider $reflectionProvider,
    ) {
    }

    /**
     * @return array{0: ErrorCode, 1: array<string, string>}|null null when the reference is legal
     */
    public function inspect(string $referencedClassName, ClassReflection $containingClass, Side $side, Scope $scope): ?array
    {
        $name = ltrim($referencedClassName, '\\');

        if ($name === '') {
            return null;
        }

        if ($this->isLegal($name, $scope)) {
            return null;
        }

        $code = $this->classifyIllegal($name);

        if ($side === Side::Shared) {
            $code = ErrorCode::SharedNonCoreSymbol;
        }

        return [$code, ['symbol' => $name, 'class' => $containingClass->getName()]];
    }

    private function isLegal(string $name, Scope $scope): bool
    {
        if (str_starts_with($name, 'Atoms\\')) {
            return true;
        }

        if ($this->reflectionProvider->hasClass($name)) {
            $classReflection = $this->reflectionProvider->getClass($name);

            if ($classReflection->isBuiltin()) {
                return true;
            }

            // Discovered-under-atomsPaths: legal even if the class's own
            // classification is OTHER (e.g. a plain helper colocated with an
            // Atom, not itself an Atom/Methods/Job/Payload).
            $file = $classReflection->getFileName();
            if ($file !== null && $this->config->isUnderAtomsPath($file)) {
                return true;
            }

            $side = $this->classifier->classify($classReflection, $scope);
            if ($side === Side::AtomSide || $side === Side::Shared) {
                return true;
            }
        } elseif (!str_contains($name, '\\')) {
            // Unresolvable and in the global namespace: likely a builtin or
            // polyfill this reflection provider doesn't have stubs for.
            // Leniency here avoids false positives; genuine monolith classes
            // are always namespaced.
            return true;
        }

        return $this->config->isAllowedNamespace($name);
    }

    private function classifyIllegal(string $name): ErrorCode
    {
        if ($this->isFacade($name)) {
            return ErrorCode::FacadeInAtom;
        }

        if (preg_match('/^(Illuminate|Laravel)\\\\/', $name) === 1) {
            return ErrorCode::FrameworkSymbolInAtom;
        }

        return ErrorCode::MonolithClassInAtom;
    }

    private function isFacade(string $name): bool
    {
        if (str_contains($name, '\\Facades\\')) {
            return true;
        }

        if ($this->reflectionProvider->hasClass($name)) {
            $classReflection = $this->reflectionProvider->getClass($name);
            if ($this->reflectionProvider->hasClass(self::FACADE_BASE) && $classReflection->isSubclassOf(self::FACADE_BASE)) {
                return true;
            }
        }

        return false;
    }
}
