<?php

declare(strict_types=1);

namespace Atoms\Cli\Command;

use Atoms\Errors\AtomsError;
use Atoms\Errors\ErrorCatalog;
use Atoms\Errors\ErrorCode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'phpstan:install', description: 'Create PHPStan configuration for Atoms')]
final class PhpstanInstallCommand extends AbstractCommand
{
    private const FILE = 'phpstan.atoms.neon';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->atomsJson($input);
            $path = $config->rootDir . '/' . self::FILE;

            if (is_file($path)) {
                $output->writeln('Kept existing ' . self::FILE . '.');
            } else {
                $paths = [$config->atomsPath];
                if ($config->sharedPath !== $config->atomsPath
                    && !str_starts_with($config->sharedPath, $config->atomsPath . '/')) {
                    $paths[] = $config->sharedPath;
                }

                $contents = "includes:\n    - vendor/atoms/phpstan-rules/rules.neon\n\nparameters:\n    level: 8\n    paths:\n";
                foreach ($paths as $sourcePath) {
                    $contents .= '        - ' . self::quote($sourcePath) . "\n";
                }
                // Replace the extension's defaults rather than appending to them.
                $contents .= "    atoms:\n        atomsPaths!:\n            - " . self::quote($config->atomsPath)
                    . "\n        sharedPaths!:\n            - " . self::quote($config->sharedPath) . "\n";

                // Exclusive creation also protects symlinks and files created
                // after the existence check. Never rewrite a user's config.
                $file = @fopen($path, 'x');
                if ($file === false) {
                    throw self::failed('could not create ' . self::FILE);
                }
                try {
                    if (@fwrite($file, $contents) !== \strlen($contents)) {
                        throw self::failed('could not write all of ' . self::FILE);
                    }
                } catch (AtomsError $e) {
                    @unlink($path);
                    throw $e;
                } finally {
                    fclose($file);
                }
                $output->writeln('Created ' . self::FILE . '.');
            }
        } catch (AtomsError $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return self::FAILURE;
        }

        if (!is_file($config->rootDir . '/vendor/atoms/phpstan-rules/rules.neon')) {
            $output->writeln('Install the analysis dependency: composer require --dev atoms/phpstan-rules');
        }
        $output->writeln('After creating an Atom, run: vendor/bin/phpstan analyse --configuration=' . self::FILE);

        return self::SUCCESS;
    }

    private static function quote(string $path): string
    {
        // PHPStan expands %parameters% even inside quoted NEON strings.
        return json_encode(str_replace('%', '%%', $path), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function failed(string $reason): AtomsError
    {
        return new AtomsError(
            ErrorCode::PhpstanConfigurationFailed,
            ErrorCatalog::format(ErrorCode::PhpstanConfigurationFailed, ['reason' => $reason]),
        );
    }
}
