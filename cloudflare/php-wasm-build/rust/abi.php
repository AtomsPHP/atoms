<?php
declare(strict_types=1);

require __DIR__ . '/timestamps.php';
require __DIR__ . '/parity.php';

if ((require __DIR__ . '/syntax.php') !== 'ATOMS' || PHP_SAPI !== 'wasm'
    || PHP_INT_SIZE !== 8 || !extension_loaded('atoms_bridge')
    || atoms_roundtrip_i64(PHP_INT_MAX) !== 9223372036854775807) {
    throw new RuntimeException('PHP, SAPI or Rust bridge ABI mismatch');
}
foreach (['bcmath', 'calendar', 'ctype', 'exif', 'fileinfo', 'filter', 'Phar', 'session',
    'tokenizer', 'Zend OPcache'] as $extension) {
    if (phpversion($extension) !== PHP_VERSION) {
        throw new RuntimeException("Unexpected $extension version");
    }
}
if (php_ini_loaded_file() !== '/internal/shared/php.ini' || ini_get('memory_limit') !== '256M'
    || ini_get('html_errors') !== '1' || ini_get('opcache.file_cache_only') !== '1'
    || !function_exists('post_message_to_js') || bcadd('9223372036854775807', '1') !== '9223372036854775808') {
    throw new RuntimeException('Embedded PHP configuration or extension behavior mismatch');
}
$bytes = str_repeat('x', 3 * 1024 * 1024);
if (strlen($bytes) !== 3 * 1024 * 1024 || memory_get_usage(true) < strlen($bytes)) {
    throw new RuntimeException('Zend heap accounting failed');
}
unset($bytes);
echo PHP_VERSION, ' ', PHP_SAPI, ' ', PHP_INT_MAX, "\n";
