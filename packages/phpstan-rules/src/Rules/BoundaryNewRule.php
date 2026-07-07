<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Rules;

use Atoms\PHPStan\BoundaryReferenceInspector;
use Atoms\PHPStan\WorldClassifier;
use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;

/**
 * Flags `new SomeClass()` inside WORLD_A/SHARED code when SomeClass is not a
 * legal boundary reference (docs/conventions.md, ATOMS-E010/E012/E014/E015).
 *
 * @implements Rule<New_>
 */
final class BoundaryNewRule implements Rule
{
    use BoundaryReferenceCheckTrait;

    public function __construct(
        private readonly WorldClassifier $classifier,
        private readonly BoundaryReferenceInspector $inspector,
    ) {
    }

    public function getNodeType(): string
    {
        return New_::class;
    }

    /**
     * @param New_ $node
     * @return list<RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        return $this->checkClassNameNode($node->class, $scope, $node->getStartLine(), 'atoms.boundary.newInstance');
    }
}
