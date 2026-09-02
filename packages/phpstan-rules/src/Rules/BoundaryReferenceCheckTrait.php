<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Rules;

use Atoms\Errors\ErrorCatalog;
use Atoms\PHPStan\BoundaryReferenceInspector;
use Atoms\PHPStan\Side;
use Atoms\PHPStan\SideClassifier;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Shared body for BoundaryNewRule, BoundaryStaticCallRule,
 * BoundaryClassConstRule, and BoundaryInstanceofRule: all four watch a
 * different node shape for the same thing — a class name reference — and
 * delegate the legality decision to {@see BoundaryReferenceInspector}.
 *
 * Requires the using class to declare (constructor-promoted, in each rule)
 * a `private readonly SideClassifier $classifier` and a
 * `private readonly BoundaryReferenceInspector $inspector` — this trait only
 * reads them via $this, it does not declare them, so each rule's own
 * constructor promotion remains the single declaration site.
 */
trait BoundaryReferenceCheckTrait
{
    /**
     * @return list<RuleError>
     */
    private function checkClassNameNode(Node $classNode, Scope $scope, int $line, string $identifier): array
    {
        if (!$classNode instanceof Node\Name) {
            // Dynamic references (`new $class()`, `$class::method()`, ...)
            // are not statically-obvious closure-walk targets; out of scope.
            return [];
        }

        $classReflection = $scope->getClassReflection();
        if ($classReflection === null) {
            return [];
        }

        $side = $this->classifier->classify($classReflection, $scope);
        if ($side !== Side::AtomSide && $side !== Side::Shared) {
            return [];
        }

        $referencedName = $scope->resolveName($classNode);

        $result = $this->inspector->inspect($referencedName, $classReflection, $side, $scope);
        if ($result === null) {
            return [];
        }

        [$code, $context] = $result;

        return [
            RuleErrorBuilder::message(ErrorCatalog::format($code, $context))
                ->identifier($identifier)
                ->line($line)
                ->build(),
        ];
    }
}
