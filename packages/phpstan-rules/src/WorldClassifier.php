<?php

declare(strict_types=1);

namespace Atoms\PHPStan;

use Atoms\Atom;
use Atoms\AtomJob;
use Atoms\AtomMethods;
use Atoms\Attributes\SharedWithAtoms;
use Atoms\Serialization\Payload;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;

/**
 * Classifies a class into one of the four World buckets (see {@see World}),
 * implementing the rule of thumb from docs/integration-plan.md §2:
 * "If it extends Atom, it leaves. If it extends AtomMethods or AtomJob, it
 * stays. If it's in Shared/, it does both — so it must be pure data."
 */
final class WorldClassifier
{
    public function __construct(private readonly AtomsRulesConfig $config)
    {
    }

    public function classify(ClassReflection $class, Scope $scope): World
    {
        if ($class->isSubclassOf(Atom::class)) {
            return World::WorldA;
        }

        if ($this->isShared($class, $scope)) {
            return World::Shared;
        }

        if ($class->isSubclassOf(AtomMethods::class) || $class->isSubclassOf(AtomJob::class)) {
            return World::WorldB;
        }

        return World::Other;
    }

    private function isShared(ClassReflection $class, Scope $scope): bool
    {
        if ($class->implementsInterface(Payload::class)) {
            return true;
        }

        if ($class->getNativeReflection()->getAttributes(SharedWithAtoms::class) !== []) {
            return true;
        }

        $file = $class->getFileName() ?? $scope->getFile();

        return $this->config->isUnderSharedPath($file);
    }
}
