<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Rules;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\PHPStan\World;
use Atoms\PHPStan\WorldClassifier;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags sleep()/usleep()/time_nanosleep()/time_sleep_until() calls inside
 * WORLD_A code (ATOMS-E101).
 *
 * On deployed workerd the guest clock does not advance within a turn: a
 * sleep() or an elapsed-time wait inside an Atom is not slow code, it is a
 * hang that holds the Atom's turn until the deadline kills it. This rule is
 * what stops a customer writing one.
 *
 * @implements Rule<FuncCall>
 */
final class AtomSleepCallRule implements Rule
{
    /** @var list<string> */
    private const SLEEP_FUNCTIONS = ['sleep', 'usleep', 'time_nanosleep', 'time_sleep_until'];

    public function __construct(private readonly WorldClassifier $classifier)
    {
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

        $world = $this->classifier->classify($classReflection, $scope);
        if ($world !== World::WorldA) {
            return [];
        }

        $functionName = ltrim($node->name->toString(), '\\');
        $lower = strtolower($functionName);

        if (!in_array($lower, self::SLEEP_FUNCTIONS, true)) {
            return [];
        }

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
