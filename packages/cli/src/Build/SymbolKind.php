<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

/**
 * Whether a referenced symbol is a class-like name or a called function name.
 */
enum SymbolKind
{
    case ClassLike;
    case FunctionCall;
}
