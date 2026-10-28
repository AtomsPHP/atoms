#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Driven by {@see \Atoms\Tests\Integration\DeploymentEnvironmentIsolationTest}.
 *
 * The Symfony half of the same property, over a different code path: the
 * bundle's console wrappers shell out through `proc_open` rather than
 * symfony/process, and a non-null environment there *replaces* the child's
 * rather than merging into it.
 *
 * `symfony/dotenv` is not a dependency of this monorepo, so this driver makes
 * the writes a dotenv loader makes — `putenv()` plus `$_ENV` — after autoload
 * and before the command, rather than calling one. That is the whole of what
 * `Dotenv::bootEnv()` does to the process for the purposes of this test, and
 * everything downstream of it here is real: the real
 * `Atoms\Symfony\Command\AtomsDeployCommand`, the real
 * `ProcOpenProcessRunner`, and a real child process.
 */

use Atoms\Symfony\Command\AtomsDeployCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require \dirname(__DIR__, 3) . '/vendor/autoload.php';

$dir = $argv[1] ?? '';
if ($dir === '' || !is_dir($dir)) {
    fwrite(\STDERR, "usage: symfony-wrapper-driver.php <app-dir>\n");
    exit(2);
}

// What a dotenv loader does to the process, after autoload has already
// snapshotted the caller's environment.
foreach (parse_ini_file($dir . '/.env', false, \INI_SCANNER_RAW) ?: [] as $name => $value) {
    if (getenv($name) === false) {
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

$command = new AtomsDeployCommand(binaryPath: $dir . '/atoms-probe', projectDir: $dir);

$output = new BufferedOutput();
$command->run(new ArrayInput(['args' => ['--env', 'production']]), $output);

echo $output->fetch();
