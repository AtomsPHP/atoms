<?php

declare(strict_types=1);

namespace Atoms\Cli\Config;

use Atoms\Errors\AtomsError;

/**
 * The environment values one Atoms deployment target resolves against, and
 * where each of them came from.
 *
 * Two layers, consulted in this order:
 *
 * 1. **the caller's environment** — the shell, CI, or process supervisor that
 *    started this command. In the standalone CLI that is simply `getenv()`; a
 *    framework wrapper is responsible for handing the child process the
 *    environment *its* caller supplied, rather than one an application
 *    bootstrap has since added to.
 * 2. **`.env.atoms.<environment>`** beside atoms.json, when present
 *    ({@see AtomsDotenv}).
 *
 * The caller wins, so this file is a convenience for populating the
 * environment layer locally and never a way to override CI. Below both sits
 * atoms.json, which is not an environment source and so is resolved by the
 * caller that owns each setting.
 *
 * Nothing here exports: no `putenv`, no `$_ENV` write. A value read from the
 * dotenv file reaches the Wrangler child process only as a setting Atoms
 * resolved and passed deliberately.
 */
final class TargetEnvironment
{
    private function __construct(
        public readonly string $environment,
        private readonly AtomsDotenv $dotenv,
    ) {
    }

    /**
     * Select the file for an already-chosen target. The target is always known
     * first: `--env` is the flag, and no application setting may change it.
     *
     * @throws AtomsError E109 when the file exists but cannot be read or parsed
     */
    public static function load(string $rootDir, string $environment): self
    {
        return new self($environment, AtomsDotenv::forTarget($rootDir, $environment));
    }

    /**
     * $name from the caller's environment, else from the selected dotenv file.
     *
     * Blank and whitespace-only read as unset in both layers, the same way
     * they do in atoms.json: every source has to agree on what "no value" is,
     * or the answer depends on which one supplied it.
     */
    public function lookup(string $name): ?ConfiguredValue
    {
        $caller = getenv($name);
        $caller = \is_string($caller) ? trim($caller) : '';
        if ($caller !== '') {
            return new ConfiguredValue($caller, 'caller environment: ' . $name);
        }

        $fromFile = $this->dotenv->value($name);
        if ($fromFile !== null) {
            return new ConfiguredValue($fromFile, AtomsDotenv::fileName($this->environment) . ': ' . $name);
        }

        return null;
    }
}
