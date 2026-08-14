<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Rules;

use Atoms\AtomJob;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\PHPStan\World;
use Atoms\PHPStan\WorldClassifier;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags `$this->dispatch(new SomeJob(...))` inside WORLD_A code (ATOMS-E104).
 *
 * `dispatch()` takes a job's class NAME: an AtomJob is World B, its source is
 * never packed into the bundle, and so the platform has no class to instantiate.
 * Passing an instance instead gets a bare argument-type error from PHPStan's own
 * checks, which says nothing about what to do about it; this rule is what
 * explains the shape.
 *
 * `$this->dispatch(SomeJob::class, ['param' => $value])` is the correct form and
 * is not flagged: naming a class is a compile-time constant, so it neither loads
 * the class nor requires it to ship.
 *
 * This rule deliberately does NOT lean on the boundary-reference rules. Those
 * treat any class discovered under the configured Atoms paths as legal (a job
 * colocated with its Atom is the normal layout), which is exactly why this
 * mistake reaches production silently — it needs a check keyed on what the
 * class IS, not on where its file sits.
 *
 * @implements Rule<MethodCall>
 */
final class AtomJobConstructionRule implements Rule
{
    public function __construct(
        private readonly WorldClassifier $classifier,
        private readonly ReflectionProvider $reflectionProvider,
    ) {
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @param MethodCall $node
     * @return list<RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->var instanceof Variable
            || $node->var->name !== 'this'
            || !$node->name instanceof Identifier
            || $node->name->toString() !== 'dispatch') {
            return [];
        }

        $classReflection = $scope->getClassReflection();
        if ($classReflection === null || $this->classifier->classify($classReflection, $scope) !== World::WorldA) {
            return [];
        }

        $first = $node->args[0] ?? null;
        if (!$first instanceof Arg || !$first->value instanceof New_ || !$first->value->class instanceof Name) {
            return [];
        }

        $jobName = ltrim($first->value->class->toString(), '\\');
        if (!$this->reflectionProvider->hasClass($jobName)) {
            return [];
        }

        if (!$this->reflectionProvider->getClass($jobName)->isSubclassOf(AtomJob::class)) {
            // Not a job at all — the boundary rules own whatever this is.
            return [];
        }

        $message = ErrorCatalog::format(ErrorCode::AtomJobConstructedInAtom, [
            'atom' => $classReflection->getName(),
            'job' => $jobName,
        ]);

        return [
            RuleErrorBuilder::message($message)
                ->identifier('atoms.dispatch.jobConstructed')
                ->line($node->getStartLine())
                ->build(),
        ];
    }
}
