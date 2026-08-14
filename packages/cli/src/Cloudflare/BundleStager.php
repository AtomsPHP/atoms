<?php

declare(strict_types=1);

namespace Atoms\Cli\Cloudflare;

use Atoms\Cli\Process\ProcessRunner;
use Atoms\Cli\Process\SymfonyProcessRunner;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;

/**
 * Stage an `atoms build` bundle into the Worker project, ready for
 * `wrangler deploy`.
 *
 * The translation itself lives in the Worker tree
 * (`scripts/bundle-from-cli.mjs`) rather than here, deliberately: composing the
 * guest filesystem needs the `Atoms\Cf` prelude and the vendored `atoms/core`
 * sources, which are the Worker's own and version with it. A PHP CLI that
 * carried its own copy of those would be a second source of truth for the
 * runtime — and the first thing to drift.
 */
final class BundleStager
{
    public const SCRIPT = 'scripts/bundle-from-cli.mjs';
    public const OUTPUT = 'src/bundle.generated.js';

    private const TIMEOUT_SECONDS = 120.0;

    private readonly ProcessRunner $runner;

    public function __construct(?ProcessRunner $runner = null)
    {
        $this->runner = $runner ?? new SymfonyProcessRunner();
    }

    /**
     * @return string the absolute path of the generated Worker bundle module
     *
     * @throws AtomsError E076 when the Worker project cannot stage a bundle,
     *                    E074 when the staging script itself fails
     */
    public function stage(CloudflareTarget $target, string $bundlePath, string $manifestPath): string
    {
        $target->assertWorkerDir();

        $script = $target->workerDir . '/' . self::SCRIPT;
        if (!is_file($script)) {
            throw new AtomsError(
                ErrorCode::WorkerDirectoryInvalid,
                ErrorCatalog::format(ErrorCode::WorkerDirectoryInvalid, [
                    'environment' => $target->environment,
                    'reason' => self::SCRIPT . " is missing from {$target->workerDir}",
                ]),
            );
        }

        $node = $this->runner->which('node');
        if ($node === null) {
            throw new AtomsError(
                ErrorCode::WorkerDirectoryInvalid,
                ErrorCatalog::format(ErrorCode::WorkerDirectoryInvalid, [
                    'environment' => $target->environment,
                    'reason' => 'node was not found on PATH, and the Worker bundle is built by a Node script',
                ]),
            );
        }

        $result = $this->runner->run(
            [$node, $script, $bundlePath, $manifestPath, self::OUTPUT],
            $target->workerDir,
            [],
            self::TIMEOUT_SECONDS,
        );

        if (!$result->ok()) {
            $diagnostic = trim($result->stderr . "\n" . $result->stdout);
            if (preg_match(
                '/ATOMS-E043: Bundle was built against atoms\/core (?<built>[^,\s]+), but this runtime supports (?<supported>\^\d+\.\d+(?:\.\d+)?)\./',
                $diagnostic,
                $match,
            ) === 1) {
                throw new AtomsError(
                    ErrorCode::CoreVersionUnsupported,
                    ErrorCatalog::format(ErrorCode::CoreVersionUnsupported, [
                        'built' => trim($match['built'], '"'),
                        'supported' => $match['supported'],
                    ]),
                );
            }

            // The script reports precisely what it could not reconcile
            // (a manifest/bundle mismatch, a missing file); surface that rather
            // than replacing it with a generic message.
            throw new AtomsError(
                ErrorCode::WranglerFailed,
                ErrorCatalog::format(ErrorCode::WranglerFailed, [
                    'command' => 'bundle-from-cli',
                    'status' => (string) $result->exitCode,
                ]) . "\n" . $diagnostic,
            );
        }

        return $target->workerDir . '/' . self::OUTPUT;
    }
}
