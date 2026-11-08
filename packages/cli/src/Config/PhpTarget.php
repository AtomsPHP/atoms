<?php

declare(strict_types=1);

namespace Atoms\Cli\Config;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/** The Atom's PHP target, independent of the PHP process running the CLI. */
final class PhpTarget
{
    private function __construct(
        public readonly string $compiler,
        public readonly string $php,
        public readonly string $version,
    ) {
    }

    public static function forCompiler(string $compiler): self
    {
        foreach (self::all() as $target) {
            if ($target->compiler === $compiler) {
                return $target;
            }
        }
        throw self::invalid('Unknown compiler ' . $compiler . '; use php-wasm or typephp.');
    }

    public static function forPhp(string $php): self
    {
        foreach (self::all() as $target) {
            if ($target->php === $php) {
                return $target;
            }
        }
        throw self::invalid('Unsupported Atom PHP target ' . $php . '; use 8.3 or 8.5.');
    }

    /** @return list<self> */
    private static function all(): array
    {
        /** @var array<string, array{php: string, php_version: string}> $pins */
        $pins = json_decode((string) file_get_contents(__DIR__ . '/../../resources/php-targets.json'), true, 512, JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($pins as $compiler => $pin) {
            $out[] = new self($compiler, $pin['php'], $pin['php_version']);
        }
        return $out;
    }

    private static function invalid(string $reason): AtomsError
    {
        return new AtomsError(ErrorCode::AtomsJsonInvalid,
            ErrorCatalog::format(ErrorCode::AtomsJsonInvalid, ['reason' => $reason]));
    }
}
