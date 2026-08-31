<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Rules;

use Atoms\Atom;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A monolith calling a method the deployed Atom manifest lacks gets
 * ATOMS-E041 at runtime. This rule catches the *statically obvious* subset
 * at build/CI time — direct chains of the shape:
 *
 *   SomeClient::get(SomeAtom::class, $id)->someMethod(...)
 *
 * where the first argument to a static `::get()` call is a class-constant
 * of an Atoms\Atom subclass. It verifies the chained method exists, is
 * public, and that the call's argument count fits the method's arity.
 *
 * Deliberate limitation: this is a syntactic pattern match, not a data-flow
 * analysis. It does not follow the stub proxy through a variable
 * (`$room = Client::get(...); $room->method()`), a helper function, or any
 * indirection — those closures are what `atoms diff`/the real manifest-based
 * checker (cli + phpstan-rules working together at build time) are for. This
 * rule exists to catch the easy, extremely common case immediately in the
 * editor/CI, not to replace the manifest-backed checker.
 *
 * @implements Rule<MethodCall>
 */
final class AtomCallSiteRule implements Rule
{
    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
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
        if (!$node->name instanceof Identifier) {
            return [];
        }

        if (!$node->var instanceof StaticCall) {
            return [];
        }

        $staticCall = $node->var;

        if (!$staticCall->class instanceof Name) {
            return [];
        }

        if (!$staticCall->name instanceof Identifier || strtolower($staticCall->name->toString()) !== 'get') {
            return [];
        }

        $args = $staticCall->getArgs();
        if (count($args) < 1) {
            return [];
        }

        $firstArg = $args[0]->value;
        if (!$firstArg instanceof ClassConstFetch) {
            return [];
        }

        if (!$firstArg->class instanceof Name) {
            return [];
        }

        if (!$firstArg->name instanceof Identifier || strtolower($firstArg->name->toString()) !== 'class') {
            return [];
        }

        $atomClassName = $scope->resolveName($firstArg->class);

        if (!$this->reflectionProvider->hasClass($atomClassName)) {
            return [];
        }

        $atomClassReflection = $this->reflectionProvider->getClass($atomClassName);

        if (!$atomClassReflection->isSubclassOf(Atom::class)) {
            return [];
        }

        $methodName = $node->name->toString();
        $line = $node->getStartLine();

        if (!$atomClassReflection->hasMethod($methodName)) {
            return [
                RuleErrorBuilder::message(
                    ErrorCatalog::format(ErrorCode::MethodNotInDeployedVersion, [
                        'method' => $methodName,
                        'type' => $atomClassName,
                        'version' => 'this source tree',
                    ]) . ' Detected statically at the call site (ATOMS-E041 semantics): the Atom stub proxy has no such public method.'
                )
                    ->identifier('atoms.callsite.methodsContract')
                    ->line($line)
                    ->build(),
            ];
        }

        $methodReflection = $atomClassReflection->getMethod($methodName, $scope);

        if (!$methodReflection->isPublic()) {
            return [
                RuleErrorBuilder::message(
                    ErrorCatalog::format(ErrorCode::MethodNotInDeployedVersion, [
                        'method' => $methodName,
                        'type' => $atomClassName,
                        'version' => 'this source tree',
                    ]) . ' Detected statically at the call site (ATOMS-E041 semantics): the method is not public, so it is not part of the deployed RPC contract.'
                )
                    ->identifier('atoms.callsite.methodsContract')
                    ->line($line)
                    ->build(),
            ];
        }

        $variant = $methodReflection->getVariants()[0] ?? null;
        if ($variant === null) {
            return [];
        }

        $parameters = $variant->getParameters();
        $minRequired = 0;
        foreach ($parameters as $parameter) {
            if (!$parameter->isOptional()) {
                $minRequired++;
            }
        }
        $maxAllowed = $variant->isVariadic() ? PHP_INT_MAX : count($parameters);

        $providedCount = count($node->getArgs());

        if ($providedCount < $minRequired || $providedCount > $maxAllowed) {
            return [
                RuleErrorBuilder::message(sprintf(
                    'ATOMS-E041: Call to %s::%s() passes %d argument(s), but the method requires %s. '
                    . 'Detected statically at the call site (ATOMS-E041 semantics): method calls on an Atom stub '
                    . 'proxy are part of the deployed contract. Fix: update the call site or the Atom signature — '
                    . 'both sides are part of the deployed contract.',
                    $atomClassName,
                    $methodName,
                    $providedCount,
                    $minRequired === $maxAllowed
                        ? (string) $minRequired
                        : sprintf('between %d and %s', $minRequired, $maxAllowed === PHP_INT_MAX ? 'unlimited' : (string) $maxAllowed),
                ))
                    ->identifier('atoms.callsite.methodsContract')
                    ->line($line)
                    ->build(),
            ];
        }

        return [];
    }
}
