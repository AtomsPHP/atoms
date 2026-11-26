<?php

declare(strict_types=1);

// Release evidence from the same universal loader and host bridge as the Worker.
$extensions = [];
foreach (get_loaded_extensions() as $extension) {
    $extensions[$extension] = phpversion($extension);
}
ksort($extensions, SORT_STRING);
$functions = get_defined_functions()['internal'];
sort($functions, SORT_STRING);
$classes = array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits());
sort($classes, SORT_STRING);

// Preserve wide integers and special floats through JSON and Node comparisons.
$constants = [];
foreach (get_defined_constants() as $name => $value) {
    $constants[$name] = is_int($value) || is_float($value) ? (string) $value : $value;
}
ksort($constants, SORT_STRING);
$ini = ini_get_all(null, false);
ksort($ini, SORT_STRING);
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE sample (id INTEGER)');
$pdo->exec('INSERT INTO sample VALUES (9223372036854775807)');
$cipher = openssl_encrypt('atoms', 'aes-128-cbc', str_repeat('k', 16), OPENSSL_RAW_DATA, str_repeat('v', 16));
if ($cipher === false) {
    throw new RuntimeException('AES-128-CBC encryption failed.');
}
$behavior = [
    'php' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'int_bytes' => PHP_INT_SIZE,
    'int_max' => (string) PHP_INT_MAX,
    'pdo_drivers' => PDO::getAvailableDrivers(),
    'sqlite_version' => $pdo->query('SELECT sqlite_version()')->fetchColumn(),
    'sqlite_int64' => (string) $pdo->query('SELECT id FROM sample')->fetchColumn(),
    'mbstring_upper' => mb_strtoupper('Straße', 'UTF-8'),
    'mbstring_regex' => mb_ereg_match('^[[:alpha:]]+$', 'é'),
    'stream_wrappers' => stream_get_wrappers(),
    'stream_transports' => stream_get_transports(),
    'gd' => gd_info(),
    'zip_aes256_encode' => ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true),
    'zip_aes256_decode' => ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, false),
    'curl_protocols' => curl_version()['protocols'],
    'openssl_version' => OPENSSL_VERSION_TEXT,
    'openssl_ciphertext' => bin2hex($cipher),
    'openssl_plaintext' => openssl_decrypt($cipher, 'aes-128-cbc', str_repeat('k', 16), OPENSSL_RAW_DATA, str_repeat('v', 16)),
    'openssl_sha256' => openssl_digest('atoms', 'sha256'),
    'openssl_ciphers' => openssl_get_cipher_methods(),
    'openssl_digests' => openssl_get_md_methods(),
];
foreach (['stream_wrappers', 'stream_transports', 'curl_protocols', 'openssl_ciphers', 'openssl_digests'] as $list) {
    sort($behavior[$list], SORT_STRING);
}
echo json_encode(compact('extensions', 'functions', 'classes', 'constants', 'ini', 'behavior'),
    JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
