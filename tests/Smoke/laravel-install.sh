#!/usr/bin/env bash
set -euo pipefail

export ATOMS_SOURCE_ROOT
ATOMS_SOURCE_ROOT=$(cd "$(dirname "$0")/../.." && pwd)
install_dir=$(mktemp -d "${TMPDIR:-/tmp}/atoms-laravel-install.XXXXXX")
trap 'rm -rf "$install_dir"' EXIT

composer create-project laravel/laravel "$install_dir/app" '^13.0' --no-interaction --no-progress
cd "$install_dir/app"

# Establish the transitive lock from #133, even if Laravel's defaults change.
# Only fixture setup allows dependency updates; the Atoms install below does not.
composer update 'guzzlehttp/psr7:^3.0' guzzlehttp/guzzle --with-all-dependencies --minimal-changes --no-interaction --no-progress
cp composer.lock composer.before-atoms.lock
composer config repositories.atoms path "$ATOMS_SOURCE_ROOT/packages/*"
composer require atoms/laravel --no-interaction --no-progress

php <<'PHP'
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

$before = json_decode(file_get_contents('composer.before-atoms.lock'), true, flags: JSON_THROW_ON_ERROR);
$locked = array_column($before['packages'], 'version', 'name');
$psr7 = Composer\InstalledVersions::getPrettyVersion('guzzlehttp/psr7');
if (!str_starts_with(ltrim($psr7 ?? '', 'v'), '3.') || $psr7 !== $locked['guzzlehttp/psr7']) {
    throw new RuntimeException('Installing Atoms must preserve the locked PSR-7 3.x version.');
}
foreach (['core', 'client', 'laravel'] as $package) {
    $installed = realpath(Composer\InstalledVersions::getInstallPath('atoms/' . $package));
    if ($installed !== realpath(getenv('ATOMS_SOURCE_ROOT') . '/packages/' . $package)) {
        throw new RuntimeException('The install check must use the checkout for atoms/' . $package);
    }
}

$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->getProvider(Atoms\Laravel\AtomsServiceProvider::class)) {
    throw new RuntimeException('Laravel did not discover the Atoms service provider.');
}
$factory = $app->make(Psr\Http\Message\RequestFactoryInterface::class);
if (!$factory instanceof GuzzleHttp\Psr7\HttpFactory) {
    throw new RuntimeException('The Atoms PSR-17 factory binding is missing.');
}
if (!$app->make(Psr\Http\Client\ClientInterface::class) instanceof GuzzleHttp\Client) {
    throw new RuntimeException('The default Atoms HTTP client binding is missing.');
}
if (!$app['router']->getRoutes()->getByName('atoms.callback')) {
    throw new RuntimeException('The Atoms callback route is missing.');
}
fwrite(STDOUT, "Atoms installed with locked PSR-7 {$psr7}; Laravel bindings and callback route are available.\n");
PHP
