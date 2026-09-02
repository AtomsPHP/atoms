<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Rules;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\PHPStan\Side;
use Atoms\PHPStan\SideClassifier;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags sleep()/usleep()/time_nanosleep()/time_sleep_until() calls inside
 * ATOM_SIDE code (ATOMS-E101).
 *
 * On deployed workerd the guest clock does not advance within a turn: a
 * sleep() or an elapsed-time wait inside an Atom is not slow code, it is a
 * hang that holds the Atom's turn until the deadline kills it. This rule is
 * what stops a customer writing one.
 *
 * An unqualified call (`sleep()`) is resolved through
 * {@see ReflectionProvider::resolveFunctionName()} rather than compared by
 * rendered name: PHP itself resolves an unqualified call to a
 * namespace-local function of the same name first, only falling back to the
 * global built-in when no such local function exists. An ATOM_SIDE namespace
 * that defines its own `sleep()`/`time_nanosleep()`/etc. and calls it
 * unqualified never reaches the dangerous global — flagging that would be a
 * false positive. `\sleep()` (fully qualified) always targets the global
 * function and is always flagged.
 *
 * @implements Rule<FuncCall>
 */
final class AtomSleepCallRule implements Rule
{
    /** @var list<string> */
    private const SLEEP_FUNCTIONS = ['sleep', 'usleep', 'time_nanosleep', 'time_sleep_until'];

    public function __construct(
        private readonly SideClassifier $classifier,
        private readonly ReflectionProvider $reflectionProvider,
    ) {
    }

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @param FuncCall $node
     * @return list<RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Name) {
            // Dynamic call ($fn()) — not a global function symbol.
            return [];
        }

        $classReflection = $scope->getClassReflection();
        if ($classReflection === null) {
            return [];
        }

        $side = $this->classifier->classify($classReflection, $scope);
        if ($side !== Side::AtomSide) {
            return [];
        }

        // Resolve what the call actually targets — PHP checks a namespace-local
        // function of the same name before falling back to the global one, so a
        // rendered-name comparison alone would false-positive on an ATOM_SIDE
        // namespace that shadows sleep()/time_nanosleep()/etc.
        $resolvedName = $this->reflectionProvider->resolveFunctionName($node->name, $scope);
        if ($resolvedName === null) {
            return [];
        }

        $lower = strtolower(ltrim($resolvedName, '\\'));

        if (!in_array($lower, self::SLEEP_FUNCTIONS, true)) {
            return [];
        }

        $functionName = ltrim($node->name->toString(), '\\');

        $message = ErrorCatalog::format(ErrorCode::SleepInAtom, [
            'symbol' => $functionName,
            'class' => $classReflection->getName(),
        ]);

        return [
            RuleErrorBuilder::message($message)
                ->identifier('atoms.clock.sleep')
                ->line($node->getStartLine())
                ->build(),
        ];
    }
}
