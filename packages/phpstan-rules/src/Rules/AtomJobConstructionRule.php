<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Rules;

use Atoms\AtomJob;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\PHPStan\Side;
use Atoms\PHPStan\SideClassifier;
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
 * Flags `$this->dispatch(new SomeJob(...))` inside ATOM_SIDE code (ATOMS-E104) —
 * the editor-time twin of the build's own check. PHPStan already reports the
 * argument type, but only this names the fix.
 *
 * Keyed on the class BEING an AtomJob, not on where its file sits: the
 * boundary-reference rules treat anything under the Atoms paths as legal, which
 * is the normal place to keep a job and so exactly why this slips past them.
 *
 * @implements Rule<MethodCall>
 */
final class AtomJobConstructionRule implements Rule
{
    public function __construct(
        private readonly SideClassifier $classifier,
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
        if ($classReflection === null || $this->classifier->classify($classReflection, $scope) !== Side::AtomSide) {
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
