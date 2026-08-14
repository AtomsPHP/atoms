<?php

declare(strict_types=1);

namespace Atoms\PHPStan\Rules;

use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Atoms\PHPStan\AtomsLayeringConfig;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\UseItem;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Analyser\Scope;
use PHPStan\File\RelativePathHelper;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Makes the package layering (docs/conventions.md §Layering) executable:
 * files under a configured zone's `paths` may not reference any of that
 * zone's `forbid` namespace prefixes (unless the reference also matches one
 * of the zone's `allow` prefixes), and may not call any of the configured
 * global framework helper functions at all.
 *
 * Deliberately implemented as a `PHPStan\Node\FileNode` rule rather than
 * hanging off `PhpParser\Node\Name` (or one of the half-dozen node types
 * that carry a class reference) directly: PHPStan does not dispatch
 * `Name` nodes to rules uniformly — a probe against this codebase caught
 * only 4 of 15 reference constructs (extends/implements, trait use,
 * attributes, typed properties, param/return types, `new`, static calls,
 * class-const fetches, `::class`, `instanceof`, `catch` types, plain and
 * group `use` imports, string literals, and docblocks). A `FileNode` walk,
 * by contrast, sees the whole already-parsed, already-name-resolved AST for
 * the file in one pass — every `PhpParser\Node\Name\FullyQualified` in it
 * covers all twelve of the reference constructs above except `use` imports
 * (which php-parser's NameResolver leaves as a plain `Name`, since the
 * segment after `use` is *itself* the definition of what's fully qualified,
 * not something resolved against one), unqualified global function calls
 * (left unresolved because they're runtime-ambiguous between the current
 * namespace and the global one), and the fully-qualified spelling of a
 * global helper call (`\config(...)`, the form PHP-CS-Fixer's
 * native_function_invocation emits): its `Name\FullyQualified` node has a
 * single part, shape-identical to any other bare symbol, so it is matched
 * against the helper list the same as the unqualified spelling instead of
 * falling into the bare-symbol check below — where no namespace prefix could
 * ever match a lone "config". A *multi-part* fully-qualified call to a
 * namespaced function (e.g. a vendor package's own `\Some\Vendor\helper()`)
 * is not a helper spelling and is left to the ordinary symbol/prefix check.
 * Those cases are collected separately below.
 *
 * Out of scope by design: `new $var(...)`, variable functions (`$fn()`),
 * and `eval()` — none carry a statically-visible symbol to check.
 *
 * @implements Rule<FileNode>
 */
final class LayeringRule implements Rule
{
    public function __construct(
        private readonly AtomsLayeringConfig $config,
        private readonly RelativePathHelper $relativePathHelper,
    ) {
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @param FileNode $node
     * @return list<RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $zones = $this->config->zonesContaining($scope->getFile());
        if ($zones === []) {
            return [];
        }

        $collector = new LayeringReferenceCollector();
        $traverser = new NodeTraverser($collector);
        $traverser->traverse($node->getNodes());

        $relativeFile = $this->relativePathHelper->getRelativePath($scope->getFile());

        /** @var array<string, RuleError> $reports */
        $reports = [];

        foreach ($zones as $zone) {
            foreach ($collector->symbols as $reference) {
                if ($this->isForbidden($reference['symbol'], $zone['forbid'], $zone['allow'])) {
                    $this->addReport(
                        $reports,
                        $reference['symbol'],
                        $reference['line'],
                        'atoms.layering.symbol',
                        $relativeFile,
                    );
                }
            }

            foreach ($collector->strings as $reference) {
                $symbol = ltrim($reference['value'], '\\');
                if ($this->isForbidden($symbol, $zone['forbid'], $zone['allow'])) {
                    $this->addReport($reports, $symbol, $reference['line'], 'atoms.layering.string', $relativeFile);
                }
            }

            foreach ($collector->docComments as $reference) {
                foreach ($this->symbolsMentionedIn($reference['text'], $zone['forbid']) as $symbol) {
                    if ($this->isForbidden($symbol, $zone['forbid'], $zone['allow'])) {
                        $this->addReport(
                            $reports,
                            $symbol,
                            $reference['line'],
                            'atoms.layering.docblock',
                            $relativeFile,
                        );
                    }
                }
            }

            // The global helper functions in $forbiddenFunctions (config(),
            // app(), ...) are Illuminate/Laravel's own — the function-call
            // sugar for the same framework its FQCNs belong to. A zone that
            // doesn't forbid the framework by namespace (e.g. atoms/laravel
            // itself, which legitimately calls response()/app()) has no
            // reason to forbid its global-helper spelling either.
            if ($this->zoneForbidsFrameworkGlobals($zone['forbid'])) {
                foreach ($collector->funcCalls as $reference) {
                    if ($this->isForbiddenFunction($reference['name'])) {
                        $this->addReport(
                            $reports,
                            $reference['name'],
                            $reference['line'],
                            'atoms.layering.helper',
                            $relativeFile,
                        );
                    }
                }
            }
        }

        return array_values($reports);
    }

    /**
     * @param list<string> $forbid
     * @param list<string> $allow
     */
    private function isForbidden(string $symbol, array $forbid, array $allow): bool
    {
        $symbol = ltrim($symbol, '\\');

        foreach ($this->prefixesWithTrailingSeparator($allow) as $prefix) {
            if (str_starts_with($symbol, $prefix)) {
                return false;
            }
        }

        foreach ($this->prefixesWithTrailingSeparator($forbid) as $prefix) {
            // Require something after the separator: a symbol that IS just
            // "Prefix\" with nothing following isn't a reference to the
            // namespace, it's namespace-prefix *data* (e.g. a classifier's
            // own list of framework prefixes to check other code against —
            // packages/cli/src/Build/SymbolClassifier.php has exactly this).
            if (str_starts_with($symbol, $prefix) && strlen($symbol) > strlen($prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isForbiddenFunction(string $name): bool
    {
        $name = strtolower(ltrim($name, '\\'));

        foreach ($this->config->forbiddenFunctions() as $forbidden) {
            if (strtolower($forbidden) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $forbid
     */
    private function zoneForbidsFrameworkGlobals(array $forbid): bool
    {
        foreach ($forbid as $prefix) {
            $prefix = trim($prefix, '\\');
            if ($prefix === 'Illuminate' || $prefix === 'Laravel') {
                return true;
            }
        }

        return false;
    }

    /**
     * Every prefix, trimmed of stray backslashes and given exactly one
     * trailing backslash, so "does $symbol start with $prefix" already
     * encodes "...followed by a backslash" — a bare namespace prefix like
     * "Laravel" must never match prose that merely contains "Laravel" as a
     * substring (e.g. "Laravel/Symfony" in a docblock, where the separator
     * is a forward slash, not a backslash).
     *
     * @param list<string> $prefixes
     * @return list<string>
     */
    private function prefixesWithTrailingSeparator(array $prefixes): array
    {
        $result = [];
        foreach ($prefixes as $prefix) {
            $prefix = trim($prefix, '\\');
            if ($prefix === '') {
                continue;
            }
            $result[] = $prefix . '\\';
        }

        return $result;
    }

    /**
     * Scans free-form doc-comment text for occurrences of a forbidden
     * namespace prefix immediately followed by a backslash (never a forward
     * slash — see {@see prefixesWithTrailingSeparator}), returning the full
     * namespaced symbol found at each occurrence so it can still be
     * exempted by an `allow` prefix like any other reference.
     *
     * @param list<string> $forbid
     * @return list<string>
     */
    private function symbolsMentionedIn(string $text, array $forbid): array
    {
        $alternation = [];
        foreach ($forbid as $prefix) {
            $prefix = trim($prefix, '\\');
            if ($prefix !== '') {
                $alternation[] = preg_quote($prefix, '/');
            }
        }

        if ($alternation === []) {
            return [];
        }

        $pattern = '/\\\\?((?:' . implode('|', $alternation) . ')(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)+)/';

        if (preg_match_all($pattern, $text, $matches) === false || $matches[1] === []) {
            return [];
        }

        /** @var list<string> $symbols */
        $symbols = array_values(array_unique($matches[1]));

        return $symbols;
    }

    /**
     * @param array<string, RuleError> $reports
     */
    private function addReport(array &$reports, string $symbol, int $line, string $identifier, string $relativeFile): void
    {
        $key = $line . '|' . $identifier . '|' . $symbol;
        if (isset($reports[$key])) {
            return;
        }

        $reports[$key] = RuleErrorBuilder::message(ErrorCatalog::format(ErrorCode::LayeringViolation, [
            'symbol' => $symbol,
            'file' => $relativeFile,
        ]))
            ->identifier($identifier)
            ->line($line)
            ->build();
    }
}

/**
 * Single pass over a file's AST collecting every construct LayeringRule
 * checks, deliberately doing no forbid/allow filtering itself — that stays
 * in LayeringRule so the same collected data can be checked against every
 * zone a file falls into.
 *
 * @internal
 */
final class LayeringReferenceCollector extends NodeVisitorAbstract
{
    /** @var list<array{symbol: string, line: int}> */
    public array $symbols = [];

    /** @var list<array{value: string, line: int}> */
    public array $strings = [];

    /** @var list<array{text: string, line: int}> */
    public array $docComments = [];

    /** @var list<array{name: string, line: int}> */
    public array $funcCalls = [];

    /**
     * Node-object ids of single-part `Name\FullyQualified` nodes already
     * recorded above in $funcCalls (the `\config(...)` spelling) — consulted
     * by the generic `Name\FullyQualified` branch below so it doesn't also
     * record the very same node as a bare "config" symbol.
     *
     * @var array<int, true>
     */
    private array $fullyQualifiedHelperNameIds = [];

    public function enterNode(Node $node): ?int
    {
        $doc = $node->getDocComment();
        if ($doc !== null) {
            $this->docComments[] = ['text' => $doc->getText(), 'line' => $doc->getStartLine()];
        }

        if ($node instanceof GroupUse) {
            foreach ($node->uses as $item) {
                $full = Name::concat($node->prefix, $item->name);
                if ($full !== null) {
                    $this->symbols[] = ['symbol' => $full->toString(), 'line' => $item->getStartLine()];
                }
            }

            // The group's items were just handled with the prefix applied;
            // visiting them again as plain UseItems below would record the
            // unprefixed suffix instead.
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof UseItem) {
            $this->symbols[] = ['symbol' => $node->name->toString(), 'line' => $node->getStartLine()];

            return null;
        }

        if ($node instanceof FuncCall && $node->name instanceof Name) {
            $isUnqualified = !$node->name instanceof Name\FullyQualified;
            $isSingleSegmentFullyQualified = !$isUnqualified && count($node->name->getParts()) === 1;

            if ($isUnqualified || $isSingleSegmentFullyQualified) {
                $this->funcCalls[] = ['name' => $node->name->toString(), 'line' => $node->getStartLine()];

                if ($isSingleSegmentFullyQualified) {
                    // `\config(...)` — see the class docblock. Mark the name
                    // node so the generic Name\FullyQualified branch below,
                    // which will still visit it as this FuncCall's `name`
                    // child, skips it instead of double-recording it as a
                    // bare symbol.
                    $this->fullyQualifiedHelperNameIds[spl_object_id($node->name)] = true;
                }
            }
        }

        if ($node instanceof Name\FullyQualified) {
            if (!isset($this->fullyQualifiedHelperNameIds[spl_object_id($node)])) {
                $this->symbols[] = ['symbol' => $node->toString(), 'line' => $node->getStartLine()];
            }

            return null;
        }

        if ($node instanceof String_) {
            $this->strings[] = ['value' => $node->value, 'line' => $node->getStartLine()];

            return null;
        }

        return null;
    }
}
