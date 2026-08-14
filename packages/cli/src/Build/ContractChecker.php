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
 * compatible arity (E030/E031); `$this->dispatchJob(X::class, [...])` must target
 * a real AtomJob whose constructor accepts those argument names (E033/E032); and
 * `$this->dispatch(new X(...))` is refused outright (E104), because an AtomJob's
 * source never ships and so cannot be constructed on the platform.
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
            $dispatchJobViolation = $this->checkDispatchJob($atom, $call);
            if ($dispatchJobViolation !== null) {
                $violations[] = $dispatchJobViolation;
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
     * Shape: `$this->dispatch(new X(...))` — the World B form, and a build error
     * inside an Atom.
     *
     * An AtomJob's source stays in the monolith, so `new X(...)` on the platform
     * raises `Class "X" not found` at the dispatch site. That failure is
     * invisible in the worst case (a dispatch inside `try { } catch
     * (\Throwable) { }` is simply never delivered, with nothing logged and no
     * failure counted), so it is caught here instead — where the fix is
     * mechanical and the message can name it.
     */
    private function checkDispatch(DiscoveredClass $atom, MethodCall $call): ?Violation
    {
        if (!$this->isThis($call->var) || !$this->nameIs($call->name, 'dispatch')) {
            return null;
        }

        $first = $call->args[0] ?? null;
        if (!$first instanceof Arg || !$first->value instanceof New_ || !$first->value->class instanceof Name) {
            return null;
        }

        $new = $first->value;
        /** @var Name $className */
        $className = $new->class;
        $jobName = $className->toString();
        $job = $this->discovery->get($jobName);

        if ($job === null || $job->kind !== ClassKind::Job) {
            return new Violation(
                ErrorCode::NotAnAtomJob,
                $atom->relativePath,
                $call->getStartLine(),
                ['atom' => $atom->fqcn, 'class' => $jobName],
                $jobName,
            );
        }

        // A real AtomJob, dispatched the one way an Atom cannot dispatch it.
        return new Violation(
            ErrorCode::AtomJobConstructedInAtom,
            $atom->relativePath,
            $call->getStartLine(),
            ['atom' => $atom->fqcn, 'job' => $jobName],
            $jobName,
        );
    }

    /**
     * Shape: `$this->dispatchJob(X::class, ['param' => $value, ...])` — the World
     * A form. The class must be a real AtomJob (E033) and the argument keys must
     * satisfy its constructor (E032); the constructor parameter names are the
     * contract on both sides of the wire.
     */
    private function checkDispatchJob(DiscoveredClass $atom, MethodCall $call): ?Violation
    {
        if (!$this->isThis($call->var) || !$this->nameIs($call->name, 'dispatchJob')) {
            return null;
        }

        $first = $call->args[0] ?? null;
        if (!$first instanceof Arg) {
            return null;
        }

        $jobName = $this->classConstName($first->value);
        if ($jobName === null) {
            return null; // a computed class name — nothing to check statically
        }

        $job = $this->discovery->get($jobName);
        if ($job === null || $job->kind !== ClassKind::Job) {
            return new Violation(
                ErrorCode::NotAnAtomJob,
                $atom->relativePath,
                $call->getStartLine(),
                ['atom' => $atom->fqcn, 'class' => $jobName],
                $jobName,
            );
        }

        $names = $this->literalArgNames($call->args[1] ?? null);
        if ($names === null) {
            return null; // not a literal array — not statically checkable
        }

        $ctor = SignatureReader::constructor($job->node);
        if ($ctor === null) {
            return $names === []
                ? null
                : $this->jobSignatureMismatch($atom, $call, $jobName);
        }

        return $ctor->acceptsArgNames($names)
            ? null
            : $this->jobSignatureMismatch($atom, $call, $jobName);
    }

    private function jobSignatureMismatch(DiscoveredClass $atom, MethodCall $call, string $jobName): Violation
    {
        return new Violation(
            ErrorCode::AtomJobSignatureMismatch,
            $atom->relativePath,
            $call->getStartLine(),
            ['atom' => $atom->fqcn, 'job' => $jobName],
            $jobName,
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
     * The string keys of a literal array argument, or null when the argument is
     * absent, spread, or not a literal array of string-keyed entries — all cases
     * this checker cannot decide and must leave to the runtime.
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
