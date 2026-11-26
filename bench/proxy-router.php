<?php
// Report request type and duration without retaining URLs with query strings, headers or bodies.
$start = hrtime(true);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
register_shutdown_function(static function () use ($path, $start): void {
    if (!preg_match('#^(/api/games(?:/in-progress|/[a-f0-9]{32}/ticket)?|/games/[a-f0-9]{32}|/atoms/callback)$#', $path)) return;
    $line = json_encode(['path' => $path, 'status' => http_response_code(), 'time' => microtime(true), 'ms' => round((hrtime(true) - $start) / 1e6, 2)]);
    file_put_contents(getenv('BENCH_REQUESTS'), $line . "\n", FILE_APPEND | LOCK_EX);
});
if ($path !== '/' && file_exists(getcwd() . '/public' . $path)) return false;
require getcwd() . '/public/index.php';
