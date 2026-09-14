#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Driven by {@see \Atoms\Tests\Integration\DeploymentEnvironmentIsolationTest}.
 *
 * A fresh process, because the property under test is about *when* things
 * happen: Composer's autoload has to run before any framework bootstrap, and
 * that ordering cannot be reproduced inside a PHPUnit process whose autoload
 * ran long ago.
 *
 * The sequence below is the real one a user gets from `php artisan
 * atoms:deploy`, minus the binary at the far end:
 *
 * 1. `vendor/autoload.php` — Composer runs this package's `files` entry, which
 *    snapshots the caller's environment.
 * 2. Laravel's own `LoadEnvironmentVariables` bootstrapper reads the
 *    application's `.env` into the process.
 * 3. The real `atoms:deploy` Artisan wrapper runs, through the real
 *    `BinaryRunner`, which spawns a real child.
 *
 * The child prints the environment it received, as JSON, on stdout.
 */

use Atoms\Laravel\Console\BinaryRunner;
use Atoms\Laravel\Console\DeployCommand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require \dirname(__DIR__, 3) . '/vendor/autoload.php';

$dir = $argv[1] ?? '';
if ($dir === '' || !is_dir($dir)) {
    fwrite(\STDERR, "usage: artisan-wrapper-driver.php <app-dir>\n");
    exit(2);
}

$app = new Application($dir);
(new LoadEnvironmentVariables())->bootstrap($app);

$command = new DeployCommand(new BinaryRunner($dir . '/atoms-probe', $dir));
$command->setLaravel($app);

$output = new BufferedOutput();
$command->run(new ArrayInput(['--env' => 'production']), $output);

echo $output->fetch();
