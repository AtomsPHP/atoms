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
$random = openssl_random_pseudo_bytes(32, $strong);
// Supply the same minimal OpenSSL configuration in MEMFS on both guests.
$config = tempnam(sys_get_temp_dir(), 'openssl-');
file_put_contents($config, "[req]\ndefault_bits = 2048\ndistinguished_name = dn\n[dn]\n");
try {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $config]);
    $signed = $key !== false && openssl_sign('atoms', $signature, $key, OPENSSL_ALGO_SHA256);
    $verified = $signed && openssl_verify('atoms', $signature, openssl_pkey_get_details($key)['key'], OPENSSL_ALGO_SHA256) === 1;
    $options = ['config' => $config, 'digest_alg' => 'sha256'];
    $request = openssl_csr_new(['commonName' => 'atoms'], $key, $options);
    $certificate = openssl_csr_sign($request, null, $key, 1, $options);
    if (!openssl_x509_export($certificate, $pem)) {
        throw new RuntimeException('Could not export the fingerprint fixture.');
    }
    $der = base64_decode(str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'], '', $pem), true);
} finally {
    unlink($config);
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
    'openssl_cert_locations' => openssl_get_cert_locations(),
    'openssl_random' => strlen($random) === 32 && $strong,
    'openssl_ec_sign_verify' => $verified,
    'openssl_ciphertext' => bin2hex($cipher),
    'openssl_plaintext' => openssl_decrypt($cipher, 'aes-128-cbc', str_repeat('k', 16), OPENSSL_RAW_DATA, str_repeat('v', 16)),
    'openssl_sha256' => openssl_digest('atoms', 'sha256'),
    'openssl_ciphers' => openssl_get_cipher_methods(),
    'openssl_digests' => openssl_get_md_methods(),
];
// The existing PHP guest returns these 16- and 32-byte SHAKE digests of "atoms".
foreach ([
    'shake128' => '795ae95781812cb57d0ff6167ec5e704',
    'shake256' => 'cb7086fb40001dba7c4129c7ed1d571d712cbf7459881932bbcfe09633e2ed2a',
] as $method => $vector) {
    $derDigest = openssl_digest($der, $method, true);
    $behavior['openssl_' . $method] = [
        'hex' => openssl_digest('atoms', $method),
        'raw_matches_vector' => openssl_digest('atoms', $method, true) === hex2bin($vector),
        'certificate_matches_der' => is_string($derDigest) && openssl_x509_fingerprint($certificate, $method, true) === $derDigest,
    ];
}
foreach (['stream_wrappers', 'stream_transports', 'curl_protocols', 'openssl_ciphers', 'openssl_digests'] as $list) {
    sort($behavior[$list], SORT_STRING);
}
echo json_encode(compact('extensions', 'functions', 'classes', 'constants', 'ini', 'behavior'),
    JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
