<?php
declare(strict_types=1);

if (PHP_VERSION !== '8.3.32' || PHP_SAPI !== 'wasm'
    || PHP_INT_SIZE !== 8 || !extension_loaded('atoms_bridge')
    || atoms_roundtrip_i64(PHP_INT_MAX) !== 9223372036854775807) {
    throw new RuntimeException('PHP, SAPI or Rust bridge ABI mismatch');
}
$bytes = str_repeat('x', 3 * 1024 * 1024);
if (strlen($bytes) !== 3 * 1024 * 1024 || memory_get_usage(true) < strlen($bytes)) {
    throw new RuntimeException('Zend heap accounting failed');
}
unset($bytes);
echo PHP_VERSION, ' ', PHP_SAPI, ' ', PHP_INT_MAX, "\n";
