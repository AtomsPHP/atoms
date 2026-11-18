<?php

declare(strict_types=1);

// An independent native reference for the PHP 8.5 pins, not a pin generator.
require __DIR__ . '/../fixtures/counter/app/Pdo/PinnedConstants85.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

check(PHP_VERSION === '8.5.9', 'Use the pinned native PHP 8.5.9 reference');
foreach (App\Pdo\PinnedConstants85::all() as $name => $value) {
    check(constant('PDO::' . $name) === $value, 'PDO::' . $name . ' differs from the reviewed pin');
}
check(method_exists(PDO::class, 'connect'), 'Native PHP 8.5 must expose PDO::connect');
$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$nulRejected = false;
try {
    $pdo->quote("a\0b");
} catch (PDOException) {
    $nulRejected = true;
}
check($nulRejected, 'Native PHP 8.5 must reject NUL in quote()');
$statement = $pdo->prepare('SELECT 1');
check($statement->setAttribute(PDO::ATTR_CURSOR, PDO::CURSOR_FWDONLY) === false,
    'Native PHP 8.5 SQLite setAttribute() must return false');
echo "Native PHP 8.5.9 PDO constants, connect(), NUL quoting and statement attributes match the reviewed contract.\n";
