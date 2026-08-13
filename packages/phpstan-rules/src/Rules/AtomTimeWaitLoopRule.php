<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Rules;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\PHPStan\World;
use Atoms\PHPStan\WorldClassifier;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags loops inside WORLD_A code that wait for wall-clock time to pass
 * (ATOMS-E102).
 *
 * On deployed workerd the guest clock does not advance within a turn: a
 * sleep() or an elapsed-time wait inside an Atom is not slow code, it is a
 * hang that holds the Atom's turn until the deadline kills it. A hand-rolled
 * `while (time() < $deadline) {}` spin-wait is the same hang wearing a loop
 * instead of a function call, so this rule is what stops a customer writing
 * one.
 *
 * A while/do-while/for loop is flagged when either:
 *  (a) its condition contains a clock read (time(), microtime(), hrtime(),
 *      gettimeofday(), or `new \DateTime`/`new \DateTimeImmutable`) at any
 *      depth; or
 *  (b) the loop is unconditional (while (true), do {} while (true), for (;;))
 *      and its body contains a clock read at any depth.
 *
 * A *bounded* loop (one whose condition is anything other than an
 * unconditional true) that merely reads the clock in its body — e.g. logging
 * `time()` on every iteration — is not flagged: (a) doesn't apply because
 * the read isn't in the condition, and (b) doesn't apply because the loop
 * isn't unconditional. An *unconditional* loop with a clock read anywhere in
 * its body, by contrast, IS flagged by (b) even when that body also contains
 * a `break` that terminates it on data rather than on elapsed time — the
 * heuristic cannot tell "stamping a record before breaking out" from
 * "spinning until enough time has passed" apart, and deliberately doesn't
 * try. The escape for a genuine data-driven unconditional loop is
 * restructuring it: give it a real bound, or hoist the clock read outside
 * the loop. The ATOMS-E061 turn deadline is the runtime backstop for
 * whatever this heuristic misses.
 *
 * A clock-function read (time(), microtime(), ...) written unqualified is
 * resolved through {@see ReflectionProvider::resolveFunctionName()} rather
 * than compared by rendered name, for the same reason as
 * {@see AtomSleepCallRule}: PHP resolves an unqualified call to a
 * namespace-local function of the same name before falling back to the
 * global built-in, so a WORLD_A namespace that shadows time() and calls it
 * unqualified must not be flagged. `new DateTime`/`new DateTimeImmutable`
 * needs no equivalent resolution step: unlike function calls, PHP resolves
 * an unqualified class name entirely at compile time — to the current
 * namespace unless a `use` import says otherwise, with no runtime fallback
 * to the global class — so php-parser's NameResolver has already rewritten
 * an unqualified reference to its fully-qualified target by the time this
 * rule sees it, and the plain string compare below is already exact (see the
 * `ShadowedClockAtom`/`shadowNewNoise` fixtures for the checked-in proof).
 *
 * @implements Rule<InClassMethodNode>
 */
final class AtomTimeWaitLoopRule implements Rule
{
    /** @var list<string> */
    private const CLOCK_FUNCTIONS = ['time', 'microtime', 'hrtime', 'gettimeofday'];

    /** @var list<string> */
    private const CLOCK_CLASSES = ['datetime', 'datetimeimmutable'];

    public function __construct(
        private readonly WorldClassifier $classifier,
        private readonly ReflectionProvider $reflectionProvider,
    ) {
    }

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * @param InClassMethodNode $node
     * @return list<RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();
        $world = $this->classifier->classify($classReflection, $scope);

        if ($world !== World::WorldA) {
            return [];
        }

        $methodNode = $node->getOriginalNode();
        if ($methodNode->stmts === null) {
            // Abstract/interface method: no body to inspect.
            return [];
        }

        $className = $classReflection->getName();
        $finder = new NodeFinder();

        /** @var list<While_|Do_|For_> $loops */
        $loops = $finder->find(
            $methodNode->stmts,
            static fn (Node $candidate): bool => $candidate instanceof While_
                || $candidate instanceof Do_
                || $candidate instanceof For_,
        );

        $errors = [];
        foreach ($loops as $loop) {
            $error = $this->checkLoop($loop, $finder, $className, $scope);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    private function checkLoop(While_|Do_|For_ $loop, NodeFinder $finder, string $className, Scope $scope): ?RuleError
    {
        [$condNodes, $isUnconditional] = $this->conditionNodes($loop);

        $symbol = $this->findClockRead($finder, $condNodes, $scope);
        if ($symbol !== null) {
            return $this->error($loop, $className, $symbol);
        }

        if (!$isUnconditional) {
            return null;
        }

        $symbol = $this->findClockRead($finder, $loop->stmts, $scope);
        if ($symbol !== null) {
            return $this->error($loop, $className, $symbol);
        }

        return null;
    }

    /**
     * Splits a loop into "the expressions its condition is made of" and
     * "whether that condition is unconditionally true" — while (true),
     * do {} while (true), and for (;;) (an empty cond list) all count.
     *
     * @return array{0: list<Node>, 1: bool}
     */
    private function conditionNodes(While_|Do_|For_ $loop): array
    {
        if ($loop instanceof For_) {
            return [$loop->cond, $loop->cond === []];
        }

        if ($this->isTrueConst($loop->cond)) {
            return [[], true];
        }

        return [[$loop->cond], false];
    }

    private function isTrueConst(Expr $cond): bool
    {
        return $cond instanceof ConstFetch && strtolower($cond->name->toString()) === 'true';
    }

    /**
     * @param list<Node> $nodes
     */
    private function findClockRead(NodeFinder $finder, array $nodes, Scope $scope): ?string
    {
        $found = $finder->findFirst($nodes, fn (Node $candidate): bool => $this->isClockRead($candidate, $scope));

        if ($found === null) {
            return null;
        }

        return $this->clockReadSymbol($found);
    }

    private function isClockRead(Node $candidate, Scope $scope): bool
    {
        if ($candidate instanceof FuncCall) {
            if (!$candidate->name instanceof Name) {
                // Dynamic call ($fn()) — not a global function symbol.
                return false;
            }

            // Resolve what the call actually targets — see the class docblock:
            // an unqualified time()/microtime()/etc. inside a namespace that
            // shadows it must not be treated as a clock read.
            $resolvedName = $this->reflectionProvider->resolveFunctionName($candidate->name, $scope);
            if ($resolvedName === null) {
                return false;
            }

            $lower = strtolower(ltrim($resolvedName, '\\'));

            return in_array($lower, self::CLOCK_FUNCTIONS, true);
        }

        if ($candidate instanceof New_) {
            if (!$candidate->class instanceof Name) {
                // Dynamic instantiation (new $class()) — not a known symbol.
                return false;
            }

            // No resolveFunctionName-equivalent step needed here: see the
            // class docblock for why an unqualified class name is already
            // fully resolved by php-parser before this rule runs.
            $lower = strtolower(ltrim($candidate->class->toString(), '\\'));

            return in_array($lower, self::CLOCK_CLASSES, true);
        }

        return false;
    }

    private function clockReadSymbol(Node $candidate): string
    {
        if ($candidate instanceof FuncCall && $candidate->name instanceof Name) {
            return ltrim($candidate->name->toString(), '\\');
        }

        if ($candidate instanceof New_ && $candidate->class instanceof Name) {
            return ltrim($candidate->class->toString(), '\\');
        }

        return '';
    }

    private function error(While_|Do_|For_ $loop, string $className, string $symbol): RuleError
    {
        $message = ErrorCatalog::format(ErrorCode::TimeWaitLoopInAtom, [
            'class' => $className,
            'symbol' => $symbol,
        ]);

        return RuleErrorBuilder::message($message)
            ->identifier('atoms.clock.waitLoop')
            ->line($loop->getStartLine())
            ->build();
    }
}
