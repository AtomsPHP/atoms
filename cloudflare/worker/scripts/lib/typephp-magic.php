<?php

declare(strict_types=1);

/**
 * Prepare TypePHP sources by tokenizing them without loading or executing them.
 * This program has no Composer dependencies and runs on the CLI's PHP 8.3.
 */

/** @return list<array{0: int, 1: string}|string> */
function significantTokens(string $source): array
{
    $tokens = [];
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                continue;
            }
            $tokens[] = [$token[0], $token[1]];
        } else {
            $tokens[] = $token;
        }
    }
    return $tokens;
}

/** @param list<array{0: int, 1: string}|string> $tokens */
function collectName(array $tokens, int &$offset): string
{
    $name = '';
    while (isset($tokens[$offset]) && is_array($tokens[$offset])
        && in_array($tokens[$offset][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR], true)
    ) {
        $name .= $tokens[$offset][1];
        $offset++;
    }
    return $name;
}

/** @param list<array{0: int, 1: string}|string> $tokens @return array<string, string> */
function importAliases(array $tokens, int &$offset): array
{
    $imports = [];
    $prefix = '';
    $statementClassImport = !(is_array($tokens[$offset] ?? null)
        && in_array($tokens[$offset][0], [T_FUNCTION, T_CONST], true));
    if (!$statementClassImport) $offset++;
    while (isset($tokens[$offset]) && $tokens[$offset] !== ';') {
        $kind = is_array($tokens[$offset]) ? $tokens[$offset][0] : null;
        $memberClassImport = !in_array($kind, [T_FUNCTION, T_CONST], true);
        $classImport = $statementClassImport && $memberClassImport;
        if (!$memberClassImport) {
            $offset++;
        }
        $name = collectName($tokens, $offset);
        if ($name === '') {
            throw new RuntimeException('Unsupported class import syntax in a TypePHP Atom source.');
        }
        if (($tokens[$offset] ?? null) === '{') {
            $prefix = rtrim($name, '\\') . '\\';
            $offset++;
            continue;
        }
        $qualified = ltrim($prefix . $name, '\\');
        $parts = explode('\\', $qualified);
        $alias = end($parts);
        if (is_array($tokens[$offset] ?? null) && $tokens[$offset][0] === T_AS) {
            $offset++;
            $alias = collectName($tokens, $offset);
            if ($alias === '' || str_contains($alias, '\\')) {
                throw new RuntimeException('Invalid class alias in a TypePHP Atom source.');
            }
        }
        if ($classImport) {
            $imports[strtolower($alias)] = $qualified;
        }
        if (($tokens[$offset] ?? null) === '}') {
            $prefix = '';
            $offset++;
        }
        if (($tokens[$offset] ?? null) === ',') {
            $offset++;
        } elseif (($tokens[$offset] ?? null) !== ';') {
            throw new RuntimeException('Unsupported class import syntax in a TypePHP Atom source.');
        }
    }
    return $imports;
}

/** @param array<string, string> $imports */
function resolveName(string $name, string $namespace, array $imports): string
{
    if (str_starts_with($name, '\\')) {
        return substr($name, 1);
    }
    if (str_starts_with(strtolower($name), 'namespace\\')) {
        return ltrim($namespace . '\\' . substr($name, 10), '\\');
    }
    $parts = explode('\\', $name);
    $alias = strtolower($parts[0]);
    if (isset($imports[$alias])) {
        array_shift($parts);
        return $imports[$alias] . ($parts === [] ? '' : '\\' . implode('\\', $parts));
    }
    return ltrim($namespace . '\\' . $name, '\\');
}

/** Refuse unsupported primary inheritance before a component can be deployed. */
function assertPrimaryClass(string $source, string $class, string $file): void
{
    $tokens = significantTokens($source);
    $namespace = '';
    $imports = [];
    $depth = 0;
    $namespaceDepth = 0;
    $primaryDepth = null;
    $found = false;
    for ($i = 0; $i < count($tokens); $i++) {
        $token = $tokens[$i];
        $kind = is_array($token) ? $token[0] : null;
        if ($kind === T_NAMESPACE && $depth === 0) {
            $i++;
            $namespace = collectName($tokens, $i);
            $imports = [];
            $namespaceDepth = ($tokens[$i] ?? null) === '{' ? 1 : 0;
            $depth = $namespaceDepth;
            continue;
        }
        if ($kind === T_USE && $depth === $namespaceDepth) {
            $i++;
            $imports = array_replace($imports, importAliases($tokens, $i));
            continue;
        }
        if ($kind === T_CLASS && $depth === $namespaceDepth
            && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_STRING
        ) {
            $declared = ltrim($namespace . '\\' . $tokens[$i + 1][1], '\\');
            if (strcasecmp($declared, ltrim($class, '\\')) === 0) {
                if ($found) throw new RuntimeException("TypePHP primary class {$class} is declared more than once in {$file}.");
                $found = true;
                $j = $i + 2;
                $parent = '';
                if (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_EXTENDS) {
                    $j++;
                    $parent = resolveName(collectName($tokens, $j), $namespace, $imports);
                }
                if (strcasecmp($parent, 'Atoms\\Atom') !== 0) {
                    throw new RuntimeException("TypePHP Atom {$class} in {$file} must directly extend Atoms\\Atom; intermediate parents are not supported by this backend.");
                }
                $primaryDepth = $depth + 1;
            }
        }
        if ($kind === T_USE && $primaryDepth !== null && $depth === $primaryDepth) {
            throw new RuntimeException("TypePHP Atom {$class} in {$file} uses a trait; primary Atom traits are not supported by this backend.");
        }
        if ($token === '{' || in_array($kind, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $depth++;
        } elseif ($token === '}') {
            if ($depth === $primaryDepth) $primaryDepth = null;
            $depth--;
            if ($depth < $namespaceDepth) {
                $namespace = '';
                $imports = [];
                $namespaceDepth = 0;
            }
        }
    }
    if (!$found) throw new RuntimeException("TypePHP primary class {$class} was not found at namespace scope in {$file}.");
}

try {
    $line = fgets(STDIN);
    if ($line === false) throw new RuntimeException('Missing TypePHP source preparation input.');
    $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($request) || !is_array($request['sources'] ?? null) || !is_array($request['atoms'] ?? null)) {
        throw new RuntimeException('Expected sources and atoms for TypePHP source preparation.');
    }
    foreach ($request['atoms'] as $atom) {
        assertPrimaryClass($request['sources'][$atom['file']], $atom['class'], $atom['file']);
    }
    $output = [];
    foreach ($request['sources'] as $guestPath => $source) {
        $rewritten = '';
        foreach (token_get_all($source) as $token) {
            if (is_string($token)) {
                $rewritten .= $token;
            } elseif ($token[0] === T_DIR) {
                $rewritten .= var_export(dirname($guestPath), true);
            } elseif ($token[0] === T_FILE) {
                $rewritten .= var_export($guestPath, true);
            } else {
                $rewritten .= $token[1];
            }
        }
        $output[$guestPath] = $rewritten;
    }
    echo json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
