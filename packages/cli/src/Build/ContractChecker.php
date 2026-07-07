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
 * compatible arity (E030/E031); `$this->dispatch(new X(...))` must target a real
 * AtomJob with a compatible constructor (E033/E032).
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

    private function checkDispatch(DiscoveredClass $atom, MethodCall $call): ?Violation
    {
        // Shape: $this->dispatch(new X(...))
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

        $ctor = SignatureReader::constructor($job->node);
        [$argc, $checkable] = $this->argCount($new->args);
        $accepts = $ctor === null ? $argc === 0 : $ctor->acceptsArgCount($argc);
        if ($checkable && !$accepts) {
            return new Violation(
                ErrorCode::AtomJobSignatureMismatch,
                $atom->relativePath,
                $call->getStartLine(),
                ['atom' => $atom->fqcn, 'job' => $jobName],
                $jobName,
            );
        }

        return null;
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
