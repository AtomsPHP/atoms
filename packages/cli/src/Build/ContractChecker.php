<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use Atoms\Errors\ErrorCode;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;

/**
 * Stage 4: check the Atom↔Methods and Atom↔AtomJob contracts statically. An Atom
 * that calls `$this->app()->foo(...)` must hit a real public Methods method with
 * compatible arity (E030/E031); `$this->dispatch(X::class, [...])` must target a
 * real AtomJob whose constructor accepts those argument names (E033/E032), and
 * must name the class rather than construct it (E104).
 */
final class ContractChecker
{
    private readonly NodeFinder $finder;

    public function __construct(
        private readonly DiscoveryResult $discovery,
        private readonly MethodsResolver $methodsResolver,
    ) {
        $this->finder = new NodeFinder();
    }

    /**
     * @return list<Violation>
     */
    public function check(DiscoveredClass $atom): array
    {
        $violations = [];

        /** @var list<MethodCall> $calls */
        $calls = $this->finder->find([$atom->node], static fn (Node $n): bool => $n instanceof MethodCall);

        foreach ($calls as $call) {
            $appViolation = $this->checkAppCall($atom, $call);
            if ($appViolation !== null) {
                $violations[] = $appViolation;
            }
            $dispatchViolation = $this->checkDispatch($atom, $call);
            if ($dispatchViolation !== null) {
                $violations[] = $dispatchViolation;
            }
        }

        return $violations;
    }

    private function checkAppCall(DiscoveredClass $atom, MethodCall $call): ?Violation
    {
        // Shape: $this->app()->method(...)
        $inner = $call->var;
        if (!$inner instanceof MethodCall
            || !$this->isThis($inner->var)
            || !$this->nameIs($inner->name, 'app')
            || !$call->name instanceof Identifier) {
            return null;
        }

        $method = $call->name->toString();
        $methodsClass = $this->methodsResolver->resolve($atom);
        $methodsClassName = $methodsClass !== null ? $methodsClass->fqcn : $atom->fqcn . '\\Methods';

        $signatures = $methodsClass !== null
            ? SignatureReader::publicMethods($methodsClass->node)
            : [];

        $match = null;
        foreach ($signatures as $sig) {
            if ($sig->name === $method) {
                $match = $sig;
                break;
            }
        }

        if ($match === null) {
            return new Violation(
                ErrorCode::UnknownMethodsMethod,
                $atom->relativePath,
                $call->getStartLine(),
                ['atom' => $atom->fqcn, 'method' => $method, 'methodsClass' => $methodsClassName],
                $methodsClassName . '::' . $method,
            );
        }

        [$argc, $checkable] = $this->argCount($call->args);
        if ($checkable && !$match->acceptsArgCount($argc)) {
            return new Violation(
                ErrorCode::MethodsSignatureMismatch,
                $atom->relativePath,
                $call->getStartLine(),
                ['atom' => $atom->fqcn, 'method' => $method, 'methodsClass' => $methodsClassName],
                $methodsClassName . '::' . $method,
            );
        }

        return null;
    }

    /**
     * Shape: `$this->dispatch(X::class, ['param' => $value, ...])`.
     *
     * The argument names must satisfy the job's constructor (E032) on a class
     * that is really an AtomJob (E033), and the first argument must be the class
     * name rather than `new X(...)` (E104).
     *
     * E104 is load-bearing rather than a nicety: nothing else in the build looks
     * at method calls on `$this`, so an instance would pass validation and die
     * as `Class "X" not found` at runtime — invisibly, if the dispatch sits in a
     * `catch (\Throwable)`.
     */
    private function checkDispatch(DiscoveredClass $atom, MethodCall $call): ?Violation
    {
        if (!$this->isThis($call->var) || !$this->nameIs($call->name, 'dispatch')) {
            return null;
        }

        $first = $call->args[0] ?? null;
        if (!$first instanceof Arg) {
            return null;
        }

        if ($first->value instanceof New_) {
            if (!$first->value->class instanceof Name) {
                return null;
            }

            $jobName = $first->value->class->toString();
            $job = $this->discovery->get($jobName);

            return $job !== null && $job->kind === ClassKind::Job
                ? $this->violation(ErrorCode::AtomJobConstructedInAtom, $atom, $call, ['job' => $jobName], $jobName)
                : $this->violation(ErrorCode::NotAnAtomJob, $atom, $call, ['class' => $jobName], $jobName);
        }

        $jobName = $this->classConstName($first->value);
        if ($jobName === null) {
            return null; // a computed class name — nothing to check statically
        }

        $job = $this->discovery->get($jobName);
        if ($job === null || $job->kind !== ClassKind::Job) {
            return $this->violation(ErrorCode::NotAnAtomJob, $atom, $call, ['class' => $jobName], $jobName);
        }

        $names = $this->literalArgNames($call->args[1] ?? null);
        if ($names === null) {
            return null; // not a literal array — not statically checkable
        }

        $ctor = SignatureReader::constructor($job->node);
        $accepts = $ctor === null ? $names === [] : $ctor->acceptsArgNames($names);

        return $accepts
            ? null
            : $this->violation(ErrorCode::AtomJobSignatureMismatch, $atom, $call, ['job' => $jobName], $jobName);
    }

    /**
     * @param array<string, string> $extra merged over the always-present `atom`
     */
    private function violation(
        ErrorCode $code,
        DiscoveredClass $atom,
        MethodCall $call,
        array $extra,
        string $symbol,
    ): Violation {
        return new Violation(
            $code,
            $atom->relativePath,
            $call->getStartLine(),
            ['atom' => $atom->fqcn] + $extra,
            $symbol,
        );
    }

    /**
     * The FQCN behind a `X::class` expression, or null for anything else.
     */
    private function classConstName(Node $expr): ?string
    {
        if (!$expr instanceof Node\Expr\ClassConstFetch
            || !$expr->class instanceof Name
            || !$expr->name instanceof Identifier
            || strtolower($expr->name->toString()) !== 'class') {
            return null;
        }

        return $expr->class->toString();
    }

    /**
     * The string keys of a literal array argument; null when it is spread or not
     * a string-keyed literal, which this checker cannot decide.
     *
     * @return list<string>|null
     */
    private function literalArgNames(?Node $arg): ?array
    {
        if ($arg === null) {
            return []; // omitted $args — the same as passing []
        }

        if (!$arg instanceof Arg || $arg->unpack || !$arg->value instanceof Node\Expr\Array_) {
            return null;
        }

        $names = [];
        foreach ($arg->value->items as $item) {
            if ($item === null || $item->unpack || !$item->key instanceof Node\Scalar\String_) {
                return null;
            }

            $names[] = $item->key->value;
        }

        return $names;
    }

    /**
     * @param array<Arg|Node\VariadicPlaceholder> $args
     * @return array{0: int, 1: bool} arg count and whether it is statically checkable
     */
    private function argCount(array $args): array
    {
        foreach ($args as $arg) {
            if (!$arg instanceof Arg) {
                return [0, false];
            }
            if ($arg->unpack || $arg->name !== null) {
                return [0, false];
            }
        }

        return [\count($args), true];
    }

    private function isThis(Node $node): bool
    {
        return $node instanceof Variable && $node->name === 'this';
    }

    private function nameIs(Node|string|null $name, string $expected): bool
    {
        return $name instanceof Identifier && $name->toString() === $expected;
    }
}
