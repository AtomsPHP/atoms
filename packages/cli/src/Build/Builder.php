<?php

declare(strict_types=1);

namespace Atoms\Cli\Build;

use Atoms\Cli\Config\AtomsComposerJson;
use Atoms\Cli\Config\AtomsJson;
use Atoms\Cli\Process\ProcessRunner;
use Atoms\Cli\Process\SymfonyProcessRunner;
use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCode;

/**
 * `atoms build`: validate, optionally run the vendor+scoper stage, then write a
 * deterministic content-addressed bundle. `--fast` skips scoper (the `atoms
 * local` / pre-commit path); a project with no approved dependencies also skips
 * it because there is nothing to prefix.
 */
final class Builder
{
    public function __construct(
        private readonly Validator $validator = new Validator(),
        private readonly ?ProcessRunner $runner = null,
    ) {
    }

    public function build(AtomsJson $config, string $outDir, bool $fast = false): BuildResult
    {
        $validation = $this->validator->validate($config);
        if (!$validation->ok()) {
            throw new AtomsError(
                ErrorCode::BundleRejected,
                'Build aborted: validation found ' . \count($validation->errors) . ' error(s). Run `atoms validate`.',
            );
        }

        $composer = AtomsComposerJson::locate($config->rootDir);
        $scoped = false;
        if (!$fast && $composer->requiredPackages() !== []) {
            $scoped = $this->runScoper($config, $validation);
        }

        return (new BundleWriter())->write($outDir, $validation->bundleFiles, $validation->manifest, $validation, $scoped);
    }

    /**
     * Best-effort execution of the vendor+scoper stage. Returns true when the
     * bundle was scoped. The bundle-file contents are unchanged in this Phase-1
     * implementation (scoper rewrites the isolated vendor tree, recorded via the
     * manifest's scoper_prefix); wiring the scoped vendor tree into the tar is a
     * follow-up.
     */
    private function runScoper(AtomsJson $config, ValidationResult $validation): bool
    {
        $runner = $this->runner ?? new SymfonyProcessRunner();
        $stage = new ScoperStage($runner);

        $scoperBin = $stage->locateScoper();
        if ($scoperBin === null) {
            return false;
        }

        $work = sys_get_temp_dir() . '/atoms-build-' . bin2hex(random_bytes(6));
        if (!@mkdir($work, 0777, true) && !is_dir($work)) {
            return false;
        }

        try {
            $composerPath = rtrim($config->rootDir, '/') . '/atoms-composer.json';
            if (is_file($composerPath)) {
                copy($composerPath, $work . '/composer.json');
            }

            $install = $runner->run(ScoperStage::composerCommand(), $work, ['COMPOSER_ALLOW_SUPERUSER' => '1'], 600.0);
            if (!$install->ok()) {
                return false;
            }

            $prefix = $validation->bundleFiles->scoperPrefix();
            $configPath = $work . '/scoper.inc.php';
            file_put_contents($configPath, ScoperStage::config($prefix));

            $out = $work . '/scoped';
            $result = $runner->run(
                ScoperStage::scoperCommand($scoperBin, $configPath, $work . '/vendor', $out),
                $work,
                [],
                600.0,
            );

            return $result->ok();
        } finally {
            self::rmrf($work);
        }
    }

    private static function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var \SplFileInfo $item */
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
