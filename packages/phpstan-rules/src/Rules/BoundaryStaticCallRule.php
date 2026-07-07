<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Rules;

use Atoms\PHPStan\BoundaryReferenceInspector;
use Atoms\PHPStan\WorldClassifier;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;

/**
 * Flags `SomeClass::method()` inside WORLD_A/SHARED code when SomeClass is
 * not a legal boundary reference (docs/conventions.md, ATOMS-E010/E012/E014/E015).
 *
 * @implements Rule<StaticCall>
 */
final class BoundaryStaticCallRule implements Rule
{
    use BoundaryReferenceCheckTrait;

    public function __construct(
        private readonly WorldClassifier $classifier,
        private readonly BoundaryReferenceInspector $inspector,
    ) {
    }

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    /**
     * @param StaticCall $node
     * @return list<RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        return $this->checkClassNameNode($node->class, $scope, $node->getStartLine(), 'atoms.boundary.staticCall');
    }
}
