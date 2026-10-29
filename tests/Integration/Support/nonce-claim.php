<?php

declare(strict_types=1);

require $argv[1];

$options = \Atoms\Client\Callback\NonceStoreOptions::fromArray(['namespace' => 'concurrency:test'], 300);
if ($argv[2] === 'laravel') {
    $pdo = new PDO('sqlite:' . $argv[3]);
    $connection = new \Illuminate\Database\SQLiteConnection($pdo, $argv[3], '', [
        'driver' => 'sqlite', 'database' => $argv[3],
    ]);
    $store = new \Atoms\Laravel\Callback\DatabaseNonceStore($connection, $options);
} else {
    $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $argv[3]]);
    $store = new \Atoms\Symfony\Callback\DoctrineNonceStore($connection, $options);
}

fwrite(STDOUT, "ready\n");
fgets(STDIN);
fwrite(STDOUT, $store->seen('same-signed-callback') ? "replay\n" : "accepted\n");
