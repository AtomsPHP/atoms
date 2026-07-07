<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Rules;

use Atoms\AtomJob;
use Atoms\AtomMethods;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\PHPStan\World;
use Atoms\PHPStan\WorldClassifier;
use Atoms\Serialization\Payload;
use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\UnionType;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Checks every public RPC signature against the serialization type algebra
 * (docs/conventions.md "Serialization type algebra"; docs/integration-plan.md
 * §4.2):
 *
 *  - WORLD_A public methods (the Atom's RPC surface; constructors are final
 *    ABI on Atoms\Atom and are never overridable, so they are skipped)
 *  - AtomMethods public methods (the $this->app() callback surface)
 *  - AtomJob's constructor only (its promoted properties ARE the dispatch
 *    contract; AtomJob's other methods, e.g. handle(), run in the monolith
 *    with full framework access and are out of scope)
 *
 * Legal types: null, bool, int, float, string, array (any), mixed, void,
 * nullable-of-legal, classes implementing Payload, \DateTimeImmutable, and
 * BackedEnum. Everything else is ATOMS-E020, except Eloquent models /
 * Doctrine entities, which get the more specific ATOMS-E021.
 *
 * @implements Rule<InClassMethodNode>
 */
final class BoundarySignatureRule implements Rule
{
    /** @var list<string> */
    private const LEGAL_SCALAR_IDENTIFIERS = ['bool', 'int', 'float', 'string', 'null', 'array', 'mixed', 'void'];

    private const ELOQUENT_MODEL = 'Illuminate\Database\Eloquent\Model';

    private const ELOQUENT_NAMESPACE_PREFIX = 'Illuminate\Database\Eloquent\\';

    private const DOCTRINE_NAMESPACE_PREFIX = 'Doctrine\ORM\\';

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

        $methodNode = $node->getOriginalNode();
        $isConstructor = strtolower($methodNode->name->toString()) === '__construct';

        if (!$this->shouldCheck($world, $classReflection, $methodNode, $isConstructor)) {
            return [];
        }

        $errors = [];
        $methodName = $methodNode->name->toString();
        $displayMethod = sprintf('%s::%s()', $classReflection->getName(), $methodName);
        $line = $methodNode->getStartLine();

        foreach ($methodNode->getParams() as $param) {
            $paramName = $param->var instanceof Variable && is_string($param->var->name)
                ? $param->var->name
                : '?';
            $location = sprintf('%s parameter $%s', $displayMethod, $paramName);
            $error = $this->checkType($param->type, $location, $scope, $line);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        // AtomJob's constructor has no meaningful return type (constructors
        // never do); everything else's return type is part of the contract.
        if (!$isConstructor) {
            $location = sprintf('%s return type', $displayMethod);
            $error = $this->checkType($methodNode->getReturnType(), $location, $scope, $line);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    private function shouldCheck(World $world, ClassReflection $classReflection, ClassMethod $methodNode, bool $isConstructor): bool
    {
        if ($world === World::WorldA) {
            // Atom::__construct is final ABI; subclasses cannot redeclare it.
            if ($isConstructor) {
                return false;
            }

            return $methodNode->isPublic();
        }

        if ($world === World::WorldB) {
            if ($classReflection->isSubclassOf(AtomJob::class)) {
                return $isConstructor;
            }

            if ($classReflection->isSubclassOf(AtomMethods::class)) {
                if ($isConstructor) {
                    return false;
                }

                return $methodNode->isPublic();
            }

            return false;
        }

        return false;
    }

    private function checkType(ComplexType|Identifier|Name|null $type, string $location, Scope $scope, int $line): ?RuleError
    {
        if ($type === null) {
            // No declared type: implicit mixed, which is on the algebra.
            return null;
        }

        if ($type instanceof NullableType) {
            return $this->checkType($type->type, $location, $scope, $line);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $member) {
                $error = $this->checkType($member, $location, $scope, $line);
                if ($error !== null) {
                    return $error;
                }
            }

            return null;
        }

        if ($type instanceof Identifier) {
            return $this->checkIdentifier($type, $location, $line);
        }

        return $this->checkClassType($type, $location, $scope, $line);
    }

    private function checkIdentifier(Identifier $type, string $location, int $line): ?RuleError
    {
        $name = strtolower($type->toString());

        if (in_array($name, self::LEGAL_SCALAR_IDENTIFIERS, true)) {
            return null;
        }

        return $this->typeError($location, $type->toString(), $line);
    }

    private function checkClassType(Name $type, string $location, Scope $scope, int $line): ?RuleError
    {
        $resolved = ltrim($scope->resolveName($type), '\\');
        $lower = strtolower($resolved);

        // static/self are not part of the wire algebra — a fluent-style
        // return doesn't serialize into anything meaningful.
        if ($lower === 'self' || $lower === 'static' || $lower === 'parent') {
            return $this->typeError($location, $resolved, $line);
        }

        if (strcasecmp($resolved, 'DateTimeImmutable') === 0) {
            return null;
        }

        if ($this->reflectionProvider->hasClass($resolved)) {
            $classReflection = $this->reflectionProvider->getClass($resolved);

            if ($classReflection->implementsInterface(Payload::class)) {
                return null;
            }

            if ($classReflection->isEnum() && $classReflection->isBackedEnum()) {
                return null;
            }

            if (
                $this->reflectionProvider->hasClass(self::ELOQUENT_MODEL)
                && $classReflection->isSubclassOf(self::ELOQUENT_MODEL)
            ) {
                return $this->ormError($location, $resolved, $line);
            }
        }

        if (str_starts_with($resolved, self::ELOQUENT_NAMESPACE_PREFIX) || $resolved === self::ELOQUENT_MODEL) {
            return $this->ormError($location, $resolved, $line);
        }

        if (str_starts_with($resolved, self::DOCTRINE_NAMESPACE_PREFIX)) {
            return $this->ormError($location, $resolved, $line);
        }

        return $this->typeError($location, $resolved, $line);
    }

    private function typeError(string $location, string $type, int $line): RuleError
    {
        return RuleErrorBuilder::message(ErrorCatalog::format(ErrorCode::BoundaryTypeOutsideAlgebra, [
            'location' => $location,
            'type' => $type,
        ]))
            ->identifier('atoms.boundary.signatureType')
            ->line($line)
            ->build();
    }

    private function ormError(string $location, string $type, int $line): RuleError
    {
        return RuleErrorBuilder::message(ErrorCatalog::format(ErrorCode::OrmObjectAtBoundary, [
            'location' => $location,
            'type' => $type,
        ]))
            ->identifier('atoms.boundary.signatureOrm')
            ->line($line)
            ->build();
    }
}
