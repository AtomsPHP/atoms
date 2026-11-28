<?php
declare(strict_types=1);

// Use the same guest inspection as the release comparison against stack #225.
ob_start();
require __DIR__ . '/inspect-guest.php';
$inventory = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
$behavior = $inventory['behavior'];
foreach (['post_message_to_js', 'dns_polyfill', 'wasm_memory_storage', 'opcache'] as $privateExtension) {
    if (isset($inventory['extensions'][$privateExtension])) {
        throw new RuntimeException("Unexpected private extension $privateExtension");
    }
}
if ($behavior['sqlite_version'] !== '3.51.0' || $behavior['sqlite_int64'] !== (string) PHP_INT_MAX
    || $behavior['mbstring_upper'] !== 'STRASSE' || !$behavior['mbstring_regex']
    || $behavior['curl_protocols'] !== ['dict', 'file', 'http', 'https']
    || $behavior['zip_aes256_encode'] || $behavior['zip_aes256_decode']
    || !$behavior['openssl_random'] || !$behavior['openssl_ec_sign_verify']
    || $behavior['openssl_plaintext'] !== 'atoms') {
    throw new RuntimeException('SQLite, mbstring, cURL, ZIP or OpenSSL behavior differs from the 8.3 control');
}
foreach (['shake128', 'shake256'] as $method) {
    $digest = $behavior['openssl_' . $method];
    if (!$digest['raw_matches_vector'] || !$digest['certificate_matches_der']) {
        throw new RuntimeException("OpenSSL $method digest or X.509 fingerprint mismatch");
    }
}
if ($behavior['stream_wrappers'] !== ['compress.zlib', 'data', 'file', 'ftp', 'ftps', 'glob', 'http', 'https', 'phar', 'php', 'zip']
    || $behavior['stream_transports'] !== ['ssl', 'tcp', 'tls', 'tlsv1.0', 'tlsv1.1', 'tlsv1.2', 'tlsv1.3', 'udg', 'udp', 'unix']) {
    throw new RuntimeException('Stream wrappers or transports differ from the 8.3 control');
}
if (PHP_CONFIG_FILE_PATH !== '/usr/local/etc' || ini_get('allow_url_include') !== ''
    || ini_get('zend.max_allowed_stack_size') !== '0' || ini_get('zend.reserved_stack_size') !== '0'
    || ini_get('zend.signal_check') !== '0') {
    throw new RuntimeException('PHP or Zend configuration differs from the 8.3 control');
}
if (!fnmatch('PHOTO.???', 'photo.png', FNM_CASEFOLD)
    || @cli_get_process_title() !== null || @cli_set_process_title('atoms') !== false
    || @proc_open(['/atoms/absent-executable'], [], $pipes) !== false) {
    throw new RuntimeException('Standard extension file or process behavior differs from the 8.3 control');
}
$image = imagecreatetruecolor(2, 2);
foreach (['png', 'jpeg', 'webp', 'bmp', 'avif'] as $format) {
    $path = __DIR__ . '/image.' . $format;
    if (!('image' . $format)($image, $path)) {
        throw new RuntimeException("Failed to encode GD $format fixture");
    }
    $decoded = ('imagecreatefrom' . $format)($path);
    if (!$decoded || imagesx($decoded) !== 2 || imagesy($decoded) !== 2) {
        throw new RuntimeException("Failed to decode GD $format fixture");
    }
    unlink($path);
}

// A 1x1 uncompressed, 24-bit TGA with a red pixel (BGR on disk).
$tga = __DIR__ . '/pixel.tga';
file_put_contents($tga, hex2bin('0000020000000000000000000100010018000000ff'));
$decoded = imagecreatefromtga($tga);
unlink($tga);
if (!$decoded || imagecolorat($decoded, 0, 0) !== 0xff0000) {
    throw new RuntimeException('GD failed to read the TGA fixture');
}
