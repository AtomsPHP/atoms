<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Rules;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\Serialization\Payload;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Payload DTOs are hydrated by promoted-constructor-property name (see
 * Atoms\Serialization\Serializer, docs/conventions.md "Wire form of a
 * Payload object"). A class that implements Payload but declares
 * non-promoted constructor parameters, or any non-static instance property
 * outside the promoted set, has state the serializer cannot see — ATOMS-E023.
 *
 * @implements Rule<InClassNode>
 */
final class PayloadHydratabilityRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @param InClassNode $node
     * @return list<RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $classReflection = $node->getClassReflection();

        if (!$classReflection->implementsInterface(Payload::class)) {
            return [];
        }

        if ($classReflection->isInterface() || $classReflection->isAbstract() || $classReflection->isTrait()) {
            return [];
        }

        $originalNode = $node->getOriginalNode();
        if (!$originalNode instanceof Node\Stmt\ClassLike) {
            return [];
        }

        $hydratable = true;

        foreach ($originalNode->getMethods() as $method) {
            if (strtolower($method->name->toString()) !== '__construct') {
                continue;
            }

            foreach ($method->getParams() as $param) {
                if ($param->flags === 0) {
                    // Not constructor-promoted (no visibility modifier):
                    // this state does not survive normalize()/denormalize().
                    $hydratable = false;
                }
            }
        }

        foreach ($originalNode->getProperties() as $property) {
            if (!$property->isStatic()) {
                // Any declared instance property outside the promoted set —
                // static analysis can't tell whether the serializer covers it.
                $hydratable = false;
            }
        }

        if ($hydratable) {
            return [];
        }

        return [
            RuleErrorBuilder::message(ErrorCatalog::format(ErrorCode::PayloadNotHydratable, [
                'class' => $classReflection->getName(),
            ]))
                ->identifier('atoms.payload.hydratability')
                ->line($originalNode->getStartLine())
                ->build(),
        ];
    }
}
