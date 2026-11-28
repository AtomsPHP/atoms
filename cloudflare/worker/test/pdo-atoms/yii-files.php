<?php

declare(strict_types=1);

// Run the same Yii case against native SQLite to discover the upstream PHP
// files it uses. Copy that exact closure into the offline fixture package.
use App\Atoms\YiiProbe\Support\YiiSmoke;

require $argv[1] . '/Yii.php';
require __DIR__ . '/yii-project/app/Atoms/YiiProbe/Support/YiiSmoke.php';

$db = new PDO('sqlite:' . $argv[2]);
$db->exec(file_get_contents(__DIR__ . '/project/app/Atoms/PdoProbe/migrations/001_rows.sql'));
$result = YiiSmoke::run(YiiSmoke::open('sqlite:' . $argv[2]), static fn (): array => []);
if ($result !== ['2.0.53', '1', '0', []]) {
    throw new RuntimeException('Yii SQLite control failed: ' . json_encode($result));
}

$root = realpath($argv[1]) . '/';
foreach (get_included_files() as $path) {
    if (str_starts_with($path, $root)) {
        echo substr($path, strlen($root)), "\n";
    }
}
