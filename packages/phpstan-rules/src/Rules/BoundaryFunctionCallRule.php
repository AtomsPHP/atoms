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
 * Flags global framework helper calls inside WORLD_A/SHARED code:
 *  - env()                      → ATOMS-E017 (Atoms cannot read the .env)
 *  - serialize()/unserialize()  → ATOMS-E018 (no native PHP serialization ever)
 *  - the rest of the helper set → ATOMS-E011 (helper does not exist at runtime)
 *
 * @implements Rule<FuncCall>
 */
final class BoundaryFunctionCallRule implements Rule
{
    /** @var list<string> */
    private const HELPERS = [
        'config', 'app', 'resolve', 'auth', 'cache', 'session', 'request',
        'response', 'route', 'view', 'event', 'broadcast', 'now', 'collect',
        'logger', 'abort', 'url', 'redirect', 'trans', 'dispatch', 'report',
        'retry', 'cookie', 'encrypt', 'decrypt', 'validator', '__',
    ];

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
            // Dynamic call ($fn(), $obj->prop()) — not a global helper symbol.
            return [];
        }

        $classReflection = $scope->getClassReflection();
        if ($classReflection === null) {
            return [];
        }

        $world = $this->classifier->classify($classReflection, $scope);
        if ($world !== World::WorldA && $world !== World::Shared) {
            return [];
        }

        $functionName = ltrim($node->name->toString(), '\\');
        $lower = strtolower($functionName);
        $line = $node->getStartLine();

        if ($lower === 'env') {
            return [$this->error(ErrorCode::EnvInAtom, ['symbol' => $functionName], $line, 'atoms.boundary.env')];
        }

        if ($lower === 'serialize' || $lower === 'unserialize') {
            return [$this->error(ErrorCode::NativeSerializationAtBoundary, [], $line, 'atoms.boundary.serialize')];
        }

        if (in_array($lower, self::HELPERS, true)) {
            return [$this->error(ErrorCode::FrameworkHelperInAtom, ['symbol' => $functionName], $line, 'atoms.boundary.helperCall')];
        }

        return [];
    }

    /**
     * @param array<string, string> $context
     */
    private function error(ErrorCode $code, array $context, int $line, string $identifier): RuleError
    {
        return RuleErrorBuilder::message(ErrorCatalog::format($code, $context))
            ->identifier($identifier)
            ->line($line)
            ->build();
    }
}
